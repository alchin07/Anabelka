<?php

class Category
{
    private static $runtimeRows = null;
    private static $effectiveStates = [];


    /**
     * Active root categories whose department and full ancestor path are active.
     */
    public static function all()
    {
        $categories = [];

        foreach (self::runtimeRows() as $category) {
            if ($category['parent_id'] !== null) {
                continue;
            }

            $category = self::attachEffectiveState($category);

            if (!empty($category['effective_active'])) {
                $categories[] = $category;
            }
        }

        return $categories;
    }


    /**
     * Canonical category lookup. A category is public only when the department,
     * category itself and every ancestor are active.
     */
    public static function findByDepartmentAndSlug(
        $departmentSlug,
        $categorySlug
    ) {
        $stmt = Database::connect()->prepare("
            SELECT
                c.*,
                d.name AS department_name,
                d.slug AS department_slug,
                d.is_active AS department_is_active
            FROM categories c
            INNER JOIN departments d
                ON d.id = c.department_id
            WHERE d.slug = :department_slug
              AND c.slug = :category_slug
            LIMIT 1
        ");
        $stmt->execute([
            'department_slug' => trim((string) $departmentSlug),
            'category_slug' => trim((string) $categorySlug)
        ]);

        $category = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$category) {
            return false;
        }

        $category = self::attachEffectiveState($category);

        return !empty($category['effective_active'])
            ? $category
            : false;
    }


    /**
     * Resolve the legacy /catalog/{slug} route only when exactly one visible
     * category has that slug across all departments.
     */
    public static function findUniqueActiveBySlug($slug)
    {
        $stmt = Database::connect()->prepare("
            SELECT
                c.*,
                d.name AS department_name,
                d.slug AS department_slug,
                d.is_active AS department_is_active
            FROM categories c
            INNER JOIN departments d
                ON d.id = c.department_id
            WHERE c.slug = :slug
            ORDER BY c.id ASC
        ");
        $stmt->execute([
            'slug' => trim((string) $slug)
        ]);

        $matches = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $category) {
            $category = self::attachEffectiveState($category);

            if (!empty($category['effective_active'])) {
                $matches[] = $category;
            }
        }

        return count($matches) === 1 ? $matches[0] : false;
    }


    /**
     * Backward-compatible lookup with safe cross-department semantics.
     */
    public static function findBySlug($slug)
    {
        return self::findUniqueActiveBySlug($slug);
    }


    public static function findById($categoryId)
    {
        $category = self::findAdminById($categoryId);

        if (!$category || empty($category['effective_active'])) {
            return false;
        }

        return $category;
    }


    public static function findAdminById($categoryId)
    {
        $stmt = Database::connect()->prepare("
            SELECT
                c.*,
                d.name AS department_name,
                d.slug AS department_slug,
                d.is_active AS department_is_active
            FROM categories c
            INNER JOIN departments d
                ON d.id = c.department_id
            WHERE c.id = :id
            LIMIT 1
        ");
        $stmt->execute([
            'id' => (int) $categoryId
        ]);

        $category = $stmt->fetch(PDO::FETCH_ASSOC);

        return $category
            ? self::attachEffectiveState($category)
            : false;
    }


    public static function children($parentId)
    {
        $stmt = Database::connect()->prepare("
            SELECT
                c.*,
                d.name AS department_name,
                d.slug AS department_slug,
                d.is_active AS department_is_active
            FROM categories c
            INNER JOIN departments d
                ON d.id = c.department_id
            WHERE c.parent_id = :parent_id
            ORDER BY c.sort_order ASC, c.name ASC, c.id ASC
        ");
        $stmt->execute([
            'parent_id' => (int) $parentId
        ]);

        $children = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $category) {
            $category = self::attachEffectiveState($category);

            if (!empty($category['effective_active'])) {
                $children[] = $category;
            }
        }

        return $children;
    }


    public static function getAllForAdmin()
    {
        $stmt = Database::connect()->query("
            SELECT
                c.id,
                c.department_id,
                c.parent_id,
                c.name,
                c.slug,
                c.description,
                c.image,
                c.sort_order,
                c.is_active,
                c.is_adult,
                d.name AS department_name,
                d.slug AS department_slug,
                d.is_active AS department_is_active,
                COALESCE(children.child_count, 0) AS child_count,
                COALESCE(products.product_count, 0) AS product_count,
                COALESCE(translations.translation_count, 0)
                    AS translation_count
            FROM categories c
            INNER JOIN departments d
                ON d.id = c.department_id
            LEFT JOIN (
                SELECT parent_id, COUNT(*) AS child_count
                FROM categories
                WHERE parent_id IS NOT NULL
                GROUP BY parent_id
            ) children
                ON children.parent_id = c.id
            LEFT JOIN (
                SELECT category_id, COUNT(*) AS product_count
                FROM products
                GROUP BY category_id
            ) products
                ON products.category_id = c.id
            LEFT JOIN (
                SELECT category_id, COUNT(*) AS translation_count
                FROM category_translations
                GROUP BY category_id
            ) translations
                ON translations.category_id = c.id
            ORDER BY
                d.sort_order ASC,
                d.name ASC,
                c.sort_order ASC,
                c.name ASC,
                c.id ASC
        ");

        $categories = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $category) {
            $categories[] = self::attachEffectiveState($category);
        }

        return $categories;
    }


    /**
     * Build one unlimited-depth forest per department for the manager UI.
     */
    public static function buildAdminForest(array $categories)
    {
        $byId = [];
        $children = [];
        $roots = [];

        foreach ($categories as $category) {
            $id = (int) ($category['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $category['children'] = [];
            $byId[$id] = $category;
        }

        foreach ($byId as $id => $category) {
            $departmentId = (int) ($category['department_id'] ?? 0);
            $parentId = (int) ($category['parent_id'] ?? 0);
            $parent = $byId[$parentId] ?? null;

            if (
                $parentId <= 0
                || !is_array($parent)
                || (int) ($parent['department_id'] ?? 0) !== $departmentId
            ) {
                $roots[$departmentId][] = $id;
            } else {
                $children[$parentId][] = $id;
            }
        }

        $visited = [];
        $build = function ($id, array $path = []) use (
            &$build,
            &$visited,
            &$byId,
            &$children
        ) {
            $id = (int) $id;
            $node = $byId[$id];

            if (isset($path[$id])) {
                $node['tree_invalid'] = true;
                return $node;
            }

            $path[$id] = true;
            $visited[$id] = true;
            $node['children'] = [];

            foreach ($children[$id] ?? [] as $childId) {
                $node['children'][] = $build($childId, $path);
            }

            return $node;
        };

        $forest = [];

        foreach ($roots as $departmentId => $rootIds) {
            foreach ($rootIds as $rootId) {
                $forest[$departmentId][] = $build($rootId);
            }
        }

        // Keep corrupted/cyclic rows visible to administrators instead of
        // silently dropping them from the manager.
        foreach ($byId as $id => $category) {
            if (isset($visited[$id])) {
                continue;
            }

            $departmentId = (int) ($category['department_id'] ?? 0);
            $node = $build($id);
            $node['tree_invalid'] = true;
            $forest[$departmentId][] = $node;
        }

        return $forest;
    }


    public static function navigationTree()
    {
        $rows = [];
        $children = [];

        foreach (self::runtimeRows() as $category) {
            $category = self::attachEffectiveState($category);

            if (empty($category['effective_active'])) {
                continue;
            }

            $category['children'] = [];
            $rows[(int) $category['id']] = $category;
            $parentId = (int) ($category['parent_id'] ?? 0);
            $children[$parentId][] = (int) $category['id'];
        }

        $build = function ($parentId, array $path = []) use (
            &$build,
            &$rows,
            &$children
        ) {
            $nodes = [];

            foreach ($children[(int) $parentId] ?? [] as $id) {
                if (isset($path[$id])) {
                    continue;
                }

                $node = $rows[$id];
                $nextPath = $path;
                $nextPath[$id] = true;
                $node['children'] = $build($id, $nextPath);
                $nodes[] = $node;
            }

            return $nodes;
        };

        return $build(0);
    }


    public static function isEffectivelyActive($categoryId)
    {
        $state = self::effectiveState((int) $categoryId);

        return !empty($state['active']);
    }


    public static function isEffectivelyAdult($categoryId)
    {
        $state = self::effectiveState((int) $categoryId);

        return !empty($state['adult']);
    }


    public static function visibleCategoryIds()
    {
        $ids = [];

        foreach (self::runtimeRows() as $category) {
            $id = (int) ($category['id'] ?? 0);

            if (self::isEffectivelyActive($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }


    public static function adultCategoryIds()
    {
        $ids = [];

        foreach (self::runtimeRows() as $category) {
            $id = (int) ($category['id'] ?? 0);

            if (
                self::isEffectivelyActive($id)
                && self::isEffectivelyAdult($id)
            ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }


    public static function catalogUrl(array $category)
    {
        $departmentSlug = trim(
            (string) ($category['department_slug'] ?? '')
        );
        $categorySlug = trim((string) ($category['slug'] ?? ''));

        if ($departmentSlug === '' || $categorySlug === '') {
            return '/Anabelka/catalog';
        }

        return '/Anabelka/catalog/'
            . rawurlencode($departmentSlug)
            . '/'
            . rawurlencode($categorySlug);
    }
    public static function resetRuntimeCache()
    {
        self::$runtimeRows = null;
        self::$effectiveStates = [];
    }


    private static function runtimeRows()
    {
        if (is_array(self::$runtimeRows)) {
            return self::$runtimeRows;
        }

        $rows = Database::connect()->query("
            SELECT
                c.*,
                d.name AS department_name,
                d.slug AS department_slug,
                d.is_active AS department_is_active
            FROM categories c
            INNER JOIN departments d
                ON d.id = c.department_id
            ORDER BY
                d.sort_order ASC,
                d.name ASC,
                c.sort_order ASC,
                c.name ASC,
                c.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        self::$runtimeRows = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $row['id'] = $id;
            $row['department_id'] = (int) ($row['department_id'] ?? 0);
            $row['parent_id'] = isset($row['parent_id'])
                ? (int) $row['parent_id']
                : null;
            self::$runtimeRows[$id] = $row;
        }

        return self::$runtimeRows;
    }


    private static function attachEffectiveState(array $category)
    {
        $id = (int) ($category['id'] ?? 0);
        $state = self::effectiveState($id);
        $category['effective_active'] = !empty($state['active']);
        $category['effective_adult'] = !empty($state['adult']);

        return $category;
    }


    private static function effectiveState($categoryId, array $path = [])
    {
        $categoryId = (int) $categoryId;

        if (isset(self::$effectiveStates[$categoryId])) {
            return self::$effectiveStates[$categoryId];
        }

        $rows = self::runtimeRows();
        $category = $rows[$categoryId] ?? null;

        if (!is_array($category) || isset($path[$categoryId])) {
            return [
                'active' => false,
                'adult' => is_array($category)
                    && !empty($category['is_adult'])
            ];
        }

        $path[$categoryId] = true;
        $active = !empty($category['is_active'])
            && !empty($category['department_is_active']);
        $adult = !empty($category['is_adult']);
        $parentId = (int) ($category['parent_id'] ?? 0);

        if ($parentId > 0) {
            $parent = $rows[$parentId] ?? null;

            if (
                !is_array($parent)
                || (int) ($parent['department_id'] ?? 0)
                    !== (int) ($category['department_id'] ?? 0)
            ) {
                $active = false;
            } else {
                $parentState = self::effectiveState($parentId, $path);
                $active = $active && !empty($parentState['active']);
                $adult = $adult || !empty($parentState['adult']);
            }
        }

        self::$effectiveStates[$categoryId] = [
            'active' => $active,
            'adult' => $adult
        ];

        return self::$effectiveStates[$categoryId];
    }
}
