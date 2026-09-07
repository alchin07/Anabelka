<?php

class ProductDepartmentSync
{
    private static $ready = false;


    public static function ensure()
    {
        if (self::$ready) {
            return;
        }

        $db = Database::connect();
        $columnStmt = $db->query(
            "SHOW COLUMNS FROM products LIKE 'department_id'"
        );
        $column = $columnStmt->fetch(PDO::FETCH_ASSOC);

        if (!$column) {
            self::$ready = true;
            return;
        }

        $fallbackDepartmentId = (int) $db->query("
            SELECT MIN(department_id)
            FROM categories
            WHERE department_id IS NOT NULL
              AND department_id > 0
        ")->fetchColumn();

        if ($fallbackDepartmentId <= 0) {
            throw new RuntimeException(
                'Не знайдено відділ для категорій товарів.'
            );
        }

        self::ensureDefault(
            $db,
            $column,
            $fallbackDepartmentId
        );

        $db->exec("
            UPDATE products AS p
            INNER JOIN categories AS c
                ON c.id = p.category_id
            SET p.department_id = c.department_id
            WHERE c.department_id IS NOT NULL
              AND p.department_id <> c.department_id
        ");

        self::ensureTrigger(
            $db,
            'anabelka_products_department_bi',
            "
                CREATE TRIGGER anabelka_products_department_bi
                BEFORE INSERT ON products
                FOR EACH ROW
                SET NEW.department_id = COALESCE(
                    (
                        SELECT c.department_id
                        FROM categories AS c
                        WHERE c.id = NEW.category_id
                        LIMIT 1
                    ),
                    NEW.department_id
                )
            "
        );

        self::ensureTrigger(
            $db,
            'anabelka_products_department_bu',
            "
                CREATE TRIGGER anabelka_products_department_bu
                BEFORE UPDATE ON products
                FOR EACH ROW
                SET NEW.department_id = COALESCE(
                    (
                        SELECT c.department_id
                        FROM categories AS c
                        WHERE c.id = NEW.category_id
                        LIMIT 1
                    ),
                    OLD.department_id
                )
            "
        );

        self::$ready = true;
    }


    private static function ensureDefault(
        PDO $db,
        array $column,
        $fallbackDepartmentId
    ) {
        if ($column['Default'] !== null) {
            return;
        }

        $type = strtolower(trim((string) ($column['Type'] ?? '')));

        if (!preg_match('/^(tinyint|smallint|mediumint|int|bigint)(\([0-9]+\))?( unsigned)?$/', $type)) {
            throw new RuntimeException(
                'Непідтримуваний тип поля products.department_id.'
            );
        }

        $nullSql = strtoupper((string) ($column['Null'] ?? 'NO')) === 'YES'
            ? 'NULL'
            : 'NOT NULL';
        $default = (int) $fallbackDepartmentId;

        $db->exec(
            "ALTER TABLE products "
            . "MODIFY department_id {$type} {$nullSql} DEFAULT {$default}"
        );
    }


    private static function ensureTrigger(PDO $db, $triggerName, $sql)
    {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND TRIGGER_NAME = :trigger_name
        ");
        $stmt->execute([
            'trigger_name' => (string) $triggerName
        ]);

        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $db->exec($sql);
    }
}
