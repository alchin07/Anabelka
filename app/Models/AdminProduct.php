<?php

class AdminProduct
{
    public static function all()
    {
        $db = Database::connect();

        $stmt = $db->query("
            SELECT
                p.id,
                p.category_id,
                p.name,
                p.slug,
                p.sku,
                p.description,
                p.is_active,
                c.name AS category_name
            FROM products p
            LEFT JOIN categories c
                ON c.id = p.category_id
            ORDER BY
                COALESCE(c.name, ''),
                p.name,
                p.id
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function updateText(
        $productId,
        $name,
        $description
    ) {
        $db = Database::connect();

        $stmt = $db->prepare("
            UPDATE products
            SET
                name = :name,
                description = :description
            WHERE id = :id
            LIMIT 1
        ");

        return $stmt->execute([
            'id' => (int) $productId,
            'name' => trim((string) $name),
            'description' =>
                trim((string) $description) !== ''
                    ? trim((string) $description)
                    : null
        ]);
    }
}
