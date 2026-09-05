<?php

class Category
{
    /**
     * Получить активные корневые категории.
     */
    public static function all()
    {
        $db = Database::connect();

        $sql = "
            SELECT
                id,
                department_id,
                parent_id,
                name,
                slug,
                description,
                image
            FROM categories
            WHERE is_active = 1
              AND parent_id IS NULL
            ORDER BY sort_order ASC, name ASC
        ";

        $stmt = $db->query($sql);

        return $stmt->fetchAll();
    }


    /**
     * Найти категорию по её slug.
     */
    public static function findBySlug($slug)
    {
        $db = Database::connect();

        $sql = "
            SELECT *
            FROM categories
            WHERE slug = :slug
              AND is_active = 1
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);

        $stmt->execute([
            'slug' => $slug
        ]);

        return $stmt->fetch();
    }


    /**
     * Найти активную категорию по ID.
     */
    public static function findById($id)
    {
        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT *
            FROM categories
            WHERE id = :id
              AND is_active = 1
            LIMIT 1
        ");

        $stmt->execute([
            'id' => (int) $id
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    /**
     * Получить дочерние категории.
     */
    public static function children($parentId)
    {
        $db = Database::connect();

        $sql = "
            SELECT *
            FROM categories
            WHERE parent_id = :parent_id
              AND is_active = 1
            ORDER BY sort_order ASC, name ASC
        ";

        $stmt = $db->prepare($sql);

        $stmt->execute([
            'parent_id' => $parentId
        ]);

        return $stmt->fetchAll();
    }


    /**
     * Получить все категории для админ-панели.
     * Включает отключённые категории.
     */
    public static function getAllForAdmin()
    {
        $db = Database::connect();

        $stmt = $db->query("
            SELECT
                id,
                department_id,
                parent_id,
                name,
                slug,
                description,
                image,
                sort_order,
                is_active
            FROM categories
            ORDER BY
                COALESCE(parent_id, 0) ASC,
                sort_order ASC,
                id ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    /**
     * Обновить украинский оригинал категории.
     * Slug и положение в дереве здесь не меняются.
     */
    public static function updateAdmin(
        $categoryId,
        $name,
        $description
    ) {
        $categoryId = (int) $categoryId;
        $name = trim((string) $name);
        $description = trim((string) $description);

        if ($categoryId <= 0 || $name === '') {
            return false;
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            UPDATE categories
            SET
                name = :name,
                description = :description
            WHERE id = :id
        ");

        return $stmt->execute([
            'id' => $categoryId,
            'name' => $name,
            'description' =>
                $description !== '' ? $description : null
        ]);
    }
}
