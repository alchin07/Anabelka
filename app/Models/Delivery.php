<?php

class Delivery
{
    /**
     * Получить все активные
     * способы доставки.
     */
    public static function getMethods()
    {
        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                id,
                name,
                slug,
                description,
                sort_order
            FROM delivery_methods
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");

        $stmt->execute();

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }


    /**
     * Получить активные службы
     * конкретного способа доставки.
     */
    public static function getServicesByMethodId(
    $deliveryMethodId
) {
    $db = Database::connect();

    $stmt = $db->prepare("
        SELECT
            id,
            delivery_method_id,
            name,
            slug,
            description,
            sort_order
        FROM delivery_services
        WHERE delivery_method_id = :delivery_method_id
          AND is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");

    $stmt->execute([
        'delivery_method_id' =>
            (int) $deliveryMethodId
    ]);

    $services =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    foreach ($services as &$service) {

        $service['options'] =
            self::getOptionsByServiceId(
                $service['id']
            );
    }

    unset($service);


    return $services;
    }


    /**
     * Получить все способы доставки
     * вместе с их службами.
     */
    public static function getMethodsWithServices()
    {
        $methods =
            self::getMethods();

        foreach ($methods as &$method) {

            $method['services'] =
                self::getServicesByMethodId(
                    $method['id']
                );
        }

        unset($method);

        return $methods;
    }

  /**
 * Найти активный способ доставки по slug.
 */
public static function findMethodBySlug($slug)
{
    $db = Database::connect();

    $stmt = $db->prepare("
        SELECT
            id,
            name,
            slug,
            description
        FROM delivery_methods
        WHERE slug = :slug
          AND is_active = 1
        LIMIT 1
    ");

    $stmt->execute([
        'slug' => $slug
    ]);

    return $stmt->fetch(
        PDO::FETCH_ASSOC
    );
}


/**
 * Найти активную службу доставки
 * внутри выбранного способа.
 */
public static function findServiceBySlug(
    $methodId,
    $serviceSlug
) {
    $db = Database::connect();

    $stmt = $db->prepare("
        SELECT
            id,
            delivery_method_id,
            name,
            slug,
            description
        FROM delivery_services
        WHERE delivery_method_id = :method_id
          AND slug = :slug
          AND is_active = 1
        LIMIT 1
    ");

    $stmt->execute([
        'method_id' => (int) $methodId,
        'slug' => $serviceSlug
    ]);

    return $stmt->fetch(
        PDO::FETCH_ASSOC
    );
}

  /**
 * Получить активные варианты получения
 * для конкретной службы доставки.
 */
public static function getOptionsByServiceId(
    $deliveryServiceId
) {
    $db = Database::connect();

    $stmt = $db->prepare("
        SELECT
            id,
            delivery_service_id,
            name,
            slug,
            description,
            sort_order
        FROM delivery_service_options
        WHERE delivery_service_id = :delivery_service_id
          AND is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");

    $stmt->execute([
        'delivery_service_id' =>
            (int) $deliveryServiceId
    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}

/**
 * Найти активный вариант получения,
 * принадлежащий выбранной службе доставки.
 */
public static function findOptionBySlug(
    $serviceId,
    $optionSlug
) {
    $db = Database::connect();

    $stmt = $db->prepare("
        SELECT
            id,
            delivery_service_id,
            name,
            slug,
            description
        FROM delivery_service_options
        WHERE delivery_service_id = :service_id
          AND slug = :slug
          AND is_active = 1
        LIMIT 1
    ");

    $stmt->execute([
        'service_id' =>
            (int) $serviceId,

        'slug' =>
            $optionSlug
    ]);

    return $stmt->fetch(
        PDO::FETCH_ASSOC
    );
}

  /**
 * Получить всю структуру доставки
 * для админ-панели.
 *
 * Включает как активные,
 * так и отключённые записи.
 */
public static function getAllForAdmin()
{
    $db = Database::connect();


    /*
     * Все способы доставки.
     */
    $stmt = $db->prepare("
        SELECT
            id,
            name,
            slug,
            description,
            is_active,
            sort_order
        FROM delivery_methods
        ORDER BY sort_order ASC, id ASC
    ");

    $stmt->execute();

    $methods =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    /*
     * Для каждого способа
     * получаем все службы.
     */
    foreach ($methods as &$method) {

        $stmt = $db->prepare("
            SELECT
                id,
                delivery_method_id,
                name,
                slug,
                description,
                is_active,
                sort_order
            FROM delivery_services
            WHERE delivery_method_id =
                :delivery_method_id
            ORDER BY sort_order ASC, id ASC
        ");

        $stmt->execute([
            'delivery_method_id' =>
                (int) $method['id']
        ]);

        $services =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        /*
         * Для каждой службы
         * получаем все варианты.
         */
        foreach ($services as &$service) {

            $stmt = $db->prepare("
                SELECT
                    id,
                    delivery_service_id,
                    name,
                    slug,
                    description,
                    is_active,
                    sort_order
                FROM delivery_service_options
                WHERE delivery_service_id =
                    :delivery_service_id
                ORDER BY sort_order ASC, id ASC
            ");

            $stmt->execute([
                'delivery_service_id' =>
                    (int) $service['id']
            ]);

            $service['options'] =
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                );
        }

        unset($service);


        $method['services'] =
            $services;
    }

    unset($method);


    return $methods;
}

  /**
 * Включить или выключить
 * способ доставки.
 */
public static function setMethodActive(
    $methodId,
    $isActive
) {
    $db = Database::connect();

    $stmt = $db->prepare("
        UPDATE delivery_methods
        SET is_active = :is_active
        WHERE id = :id
    ");

    return $stmt->execute([
        'is_active' =>
            $isActive ? 1 : 0,

        'id' =>
            (int) $methodId
    ]);
}

  /**
 * Включить или выключить
 * службу доставки.
 */
public static function setServiceActive(
    $serviceId,
    $isActive
) {
    $db = Database::connect();

    $stmt = $db->prepare("
        UPDATE delivery_services
        SET is_active = :is_active
        WHERE id = :id
    ");

    return $stmt->execute([
        'is_active' =>
            $isActive ? 1 : 0,

        'id' =>
            (int) $serviceId
    ]);
}

  /**
 * Включить или выключить
 * вариант получения.
 */
public static function setOptionActive(
    $optionId,
    $isActive
) {
    $db = Database::connect();

    $stmt = $db->prepare("
        UPDATE delivery_service_options
        SET is_active = :is_active
        WHERE id = :id
    ");

    return $stmt->execute([
        'is_active' =>
            $isActive ? 1 : 0,

        'id' =>
            (int) $optionId
    ]);
}

  /**
 * Обновить способ доставки.
 */
public static function updateMethod(
    $id,
    $name,
    $description
) {
    $db =
        Database::connect();

    $stmt =
        $db->prepare("
            UPDATE delivery_methods
            SET
                name = :name,
                description = :description
            WHERE id = :id
        ");

    return $stmt->execute([
        'name' =>
            $name,

        'description' =>
            $description !== ''
                ? $description
                : null,

        'id' =>
            (int) $id
    ]);
}


/**
 * Обновить службу доставки.
 */
public static function updateService(
    $id,
    $name,
    $description
) {
    $db =
        Database::connect();

    $stmt =
        $db->prepare("
            UPDATE delivery_services
            SET
                name = :name,
                description = :description
            WHERE id = :id
        ");

    return $stmt->execute([
        'name' =>
            $name,

        'description' =>
            $description !== ''
                ? $description
                : null,

        'id' =>
            (int) $id
    ]);
}


/**
 * Обновить вариант получения.
 */
public static function updateOption(
    $id,
    $name,
    $description
) {
    $db =
        Database::connect();

    $stmt =
        $db->prepare("
            UPDATE delivery_service_options
            SET
                name = :name,
                description = :description
            WHERE id = :id
        ");

    return $stmt->execute([
        'name' =>
            $name,

        'description' =>
            $description !== ''
                ? $description
                : null,

        'id' =>
            (int) $id
    ]);
}

  /**
 * Удалить способ доставки.
 */
public static function deleteMethod(
    $id
) {
    $db = Database::connect();

    $stmt = $db->prepare("
        DELETE FROM delivery_methods
        WHERE id = :id
    ");

    return $stmt->execute([
        'id' => (int) $id
    ]);
}


/**
 * Удалить службу доставки.
 */
public static function deleteService(
    $id
) {
    $db = Database::connect();

    $stmt = $db->prepare("
        DELETE FROM delivery_services
        WHERE id = :id
    ");

    return $stmt->execute([
        'id' => (int) $id
    ]);
}


/**
 * Удалить вариант получения.
 */
public static function deleteOption(
    $id
) {
    $db = Database::connect();

    $stmt = $db->prepare("
        DELETE FROM delivery_service_options
        WHERE id = :id
    ");

    return $stmt->execute([
        'id' => (int) $id
    ]);
}

  /**
 * Создать способ доставки.
 */
public static function createMethod(
    $name,
    $description
) {
    $db =
        Database::connect();


    /*
     * Определяем новый порядок.
     * Новый способ доставки
     * всегда добавляется в конец.
     */
    $stmt =
        $db->query("
            SELECT
                COALESCE(
                    MAX(sort_order),
                    0
                )
            FROM delivery_methods
        ");


    $maxSortOrder =
        (int) $stmt->fetchColumn();


    $sortOrder =
        $maxSortOrder + 10;


    /*
     * Создаём системное имя
     * автоматически из названия.
     */
    $baseSlug =
        self::makeSlug(
            $name
        );


    if ($baseSlug === '') {

        $baseSlug =
            'delivery';
    }


    $slug =
        $baseSlug;

    $number =
        2;


    /*
     * Если такой slug уже существует,
     * добавляем -2, -3 и так далее.
     */
    while (
        self::methodSlugExists(
            $db,
            $slug
        )
    ) {

        $slug =
            $baseSlug
            . '-'
            . $number;

        $number++;
    }


    $stmt =
        $db->prepare("
            INSERT INTO delivery_methods
            (
                name,
                slug,
                description,
                is_active,
                sort_order
            )
            VALUES
            (
                :name,
                :slug,
                :description,
                1,
                :sort_order
            )
        ");


    $stmt->execute([
        'name' =>
            $name,

        'slug' =>
            $slug,

        'description' =>
            $description !== ''
                ? $description
                : null,

        'sort_order' =>
            $sortOrder
    ]);


    return [
        'id' =>
            (int) $db->lastInsertId(),

        'slug' =>
            $slug,

        'sort_order' =>
            $sortOrder
    ];
}

  public static function createService(
    $deliveryMethodId,
    $name,
    $description
) {
    $db =
        Database::connect();


    /*
     * Определяем новый порядок.
     *
     * Новая служба всегда
     * добавляется в конец служб
     * своего способа доставки.
     */
    $stmt =
        $db->prepare("
            SELECT
                COALESCE(
                    MAX(sort_order),
                    0
                )
            FROM delivery_services
            WHERE delivery_method_id =
                :delivery_method_id
        ");


    $stmt->execute([
        'delivery_method_id' =>
            (int) $deliveryMethodId
    ]);


    $maxSortOrder =
        (int) $stmt->fetchColumn();


    $sortOrder =
        $maxSortOrder + 10;


    /*
     * Создаём системное имя
     * автоматически из названия.
     */
    $baseSlug =
        self::makeSlug(
            $name
        );


    if ($baseSlug === '') {

        $baseSlug =
            'service';
    }


    $slug =
        $baseSlug;

    $number =
        2;


    /*
     * Если такой slug уже существует,
     * добавляем -2, -3 и так далее.
     */
    while (
        self::serviceSlugExists(
            $db,
            $slug
        )
    ) {

        $slug =
            $baseSlug
            . '-'
            . $number;

        $number++;
    }


    $stmt =
        $db->prepare("
            INSERT INTO delivery_services
            (
                delivery_method_id,
                name,
                slug,
                description,
                is_active,
                sort_order
            )
            VALUES
            (
                :delivery_method_id,
                :name,
                :slug,
                :description,
                1,
                :sort_order
            )
        ");


    $stmt->execute([
        'delivery_method_id' =>
            (int) $deliveryMethodId,

        'name' =>
            $name,

        'slug' =>
            $slug,

        'description' =>
            $description !== ''
                ? $description
                : null,

        'sort_order' =>
            $sortOrder
    ]);


    return [
        'id' =>
            (int) $db->lastInsertId(),

        'slug' =>
            $slug,

        'sort_order' =>
            $sortOrder
    ];
  }
  private static function serviceSlugExists(
    $db,
    $slug
) {
    $stmt = $db->prepare("
        SELECT id
        FROM delivery_services
        WHERE slug = :slug
        LIMIT 1
    ");

    $stmt->execute([
        'slug' => $slug
    ]);

    return (bool)
        $stmt->fetchColumn();
  }

  private static function methodSlugExists(
    $db,
    $slug
) {
    $stmt = $db->prepare("
        SELECT id
        FROM delivery_methods
        WHERE slug = :slug
        LIMIT 1
    ");

    $stmt->execute([
        'slug' => $slug
    ]);

    return (bool)
        $stmt->fetchColumn();
  }

  private static function optionSlugExists(
    $db,
    $deliveryServiceId,
    $slug
) {
    $stmt = $db->prepare("
        SELECT id
        FROM delivery_service_options
        WHERE delivery_service_id = :delivery_service_id
          AND slug = :slug
        LIMIT 1
    ");

    $stmt->execute([
        'delivery_service_id' =>
            (int) $deliveryServiceId,

        'slug' =>
            $slug
    ]);

    return (bool)
        $stmt->fetchColumn();
  }

  private static function makeSlug(
    $text
) {
    $map = [
        'а' => 'a',
        'б' => 'b',
        'в' => 'v',
        'г' => 'g',
        'ґ' => 'g',
        'д' => 'd',
        'е' => 'e',
        'ё' => 'e',
        'є' => 'ye',
        'ж' => 'zh',
        'з' => 'z',
        'и' => 'i',
        'і' => 'i',
        'ї' => 'yi',
        'й' => 'y',
        'к' => 'k',
        'л' => 'l',
        'м' => 'm',
        'н' => 'n',
        'о' => 'o',
        'п' => 'p',
        'р' => 'r',
        'с' => 's',
        'т' => 't',
        'у' => 'u',
        'ф' => 'f',
        'х' => 'h',
        'ц' => 'ts',
        'ч' => 'ch',
        'ш' => 'sh',
        'щ' => 'sch',
        'ъ' => '',
        'ы' => 'y',
        'ь' => '',
        'э' => 'e',
        'ю' => 'yu',
        'я' => 'ya'
    ];


    $text =
        mb_strtolower(
            trim($text),
            'UTF-8'
        );


    $text =
        strtr(
            $text,
            $map
        );


    $text =
        preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $text
        );


    return trim(
        $text,
        '-'
    );
  }

  public static function createOption(
    $deliveryServiceId,
    $name,
    $description
) {
    $db =
        Database::connect();


    /*
     * Определяем новый порядок.
     *
     * Новая опция всегда
     * добавляется в конец опций
     * своей службы доставки.
     */
    $stmt =
        $db->prepare("
            SELECT
                COALESCE(
                    MAX(sort_order),
                    0
                )
            FROM delivery_service_options
            WHERE delivery_service_id =
                :delivery_service_id
        ");


    $stmt->execute([
        'delivery_service_id' =>
            (int) $deliveryServiceId
    ]);


    $maxSortOrder =
        (int) $stmt->fetchColumn();


    $sortOrder =
        $maxSortOrder + 10;


    $baseSlug =
        self::makeSlug(
            $name
        );


    if ($baseSlug === '') {

        $baseSlug =
            'option';
    }


    $slug =
        $baseSlug;

    $number =
        2;


    while (
        self::optionSlugExists(
            $db,
            $deliveryServiceId,
            $slug
        )
    ) {

        $slug =
            $baseSlug
            . '-'
            . $number;

        $number++;
    }


    $stmt =
        $db->prepare("
            INSERT INTO delivery_service_options
            (
                delivery_service_id,
                name,
                slug,
                description,
                is_active,
                sort_order
            )
            VALUES
            (
                :delivery_service_id,
                :name,
                :slug,
                :description,
                1,
                :sort_order
            )
        ");


    $stmt->execute([
        'delivery_service_id' =>
            (int) $deliveryServiceId,

        'name' =>
            $name,

        'slug' =>
            $slug,

        'description' =>
            $description !== ''
                ? $description
                : null,

        'sort_order' =>
            $sortOrder
    ]);


    return [
        'id' =>
            (int) $db->lastInsertId(),

        'slug' =>
            $slug,

        'sort_order' =>
            $sortOrder
    ];
  }
}