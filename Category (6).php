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
}