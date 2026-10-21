<?php

class Department
{
    public static function allForAdmin()
    {
        $stmt = Database::connect()->query("
            SELECT
                id,
                name,
                slug,
                description,
                image,
                is_active,
                sort_order
            FROM departments
            ORDER BY sort_order ASC, name ASC, id ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
