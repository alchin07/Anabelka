<?php

class CategoryManager
{
    public static function create(array $input)
    {
        $name = self::requiredName($input['name'] ?? '');
        $description = self::nullableText($input['description'] ?? '');
        $parentId = self::nullableId($input['parent_id'] ?? null);
        $requestedDepartmentId = (int) ($input['department_id'] ?? 0);
        $isActive = !empty($input['is_active']) ? 1 : 0;
        $isAdult = !empty($input['is_adult']) ? 1 : 0;
        $db = Database::connect();

        try {
            $db->beginTransaction();

            if ($parentId !== null) {
                $parent = self::lockCategory($db, $parentId);
                $departmentId = (int) $parent['department_id'];

                if (
                    $requestedDepartmentId > 0
                    && $requestedDepartmentId !== $departmentId
                ) {
                    throw new DomainException(
                        'Підрозділ дочірньої категорії визначається її батьківською категорією.'
                    );
                }
            } else {
                $departmentId = $requestedDepartmentId;
            }

            if ($departmentId <= 0) {
                throw new DomainException('Оберіть підрозділ категорії.');
            }

            self::lockDepartment($db, $departmentId);
            $slug = self::uniqueSlug($db, $departmentId, $name);
            $sortOrder = self::nextSortOrder(
                $db,
                $departmentId,
                $parentId
            );

            $stmt = $db->prepare("
                INSERT INTO categories
                (
                    department_id,
                    parent_id,
                    name,
                    slug,
                    description,
                    is_active,
                    is_adult,
                    sort_order
                )
                VALUES
                (
                    :department_id,
                    :parent_id,
                    :name,
                    :slug,
                    :description,
                    :is_active,
                    :is_adult,
                    :sort_order
                )
            ");
            $stmt->execute([
                'department_id' => $departmentId,
                'parent_id' => $parentId,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'is_active' => $isActive,
                'is_adult' => $isAdult,
                'sort_order' => $sortOrder
            ]);

            $categoryId = (int) $db->lastInsertId();

            if ($categoryId <= 0) {
                throw new RuntimeException(
                    'База даних не підтвердила створення категорії.'
                );
            }

            $db->commit();
            Category::resetRuntimeCache();

            return [
                'id' => $categoryId,
                'department_id' => $departmentId,
                'parent_id' => $parentId,
                'slug' => $slug
            ];
        } catch (Throwable $e) {
            self::rollBack($db);
            throw $e;
        }
    }


    public static function update(
        $categoryId,
        array $input,
        array $activeLanguages
    ) {
        $categoryId = (int) $categoryId;
        $name = self::requiredName($input['name'] ?? '');
        $description = self::nullableText($input['description'] ?? '');
        $isActive = !empty($input['is_active']) ? 1 : 0;
        $isAdult = !empty($input['is_adult']) ? 1 : 0;
        $db = Database::connect();

        try {
            CategoryTranslator::getForCategory(0);
            $db->beginTransaction();
            $current = self::lockCategory($db, $categoryId);
            $before = CategoryTranslator::getForCategory(
                $categoryId,
                true
            );
            $sourceChanged = TranslationWorkflow::sourceChanged(
                $current['name'] ?? '',
                $current['description'] ?? '',
                $name,
                $description
            );

            $stmt = $db->prepare("
                UPDATE categories
                SET
                    name = :name,
                    description = :description,
                    is_active = :is_active,
                    is_adult = :is_adult
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $categoryId,
                'name' => $name,
                'description' => $description,
                'is_active' => $isActive,
                'is_adult' => $isAdult
            ]);

            if ($sourceChanged) {
                CategoryTranslator::markOutdated($categoryId);
            }

            $expected = self::saveTranslations(
                $categoryId,
                $input,
                $activeLanguages,
                $before,
                $sourceChanged
            );
            self::verifyTranslations(
                CategoryTranslator::getForCategory($categoryId),
                $expected
            );

            $verify = $db->prepare("
                SELECT name, description, is_active, is_adult
                FROM categories
                WHERE id = :id
                LIMIT 1
            ");
            $verify->execute(['id' => $categoryId]);
            $stored = $verify->fetch(PDO::FETCH_ASSOC);

            if (
                !$stored
                || trim((string) $stored['name']) !== $name
                || trim((string) ($stored['description'] ?? ''))
                    !== trim((string) ($description ?? ''))
                || (int) $stored['is_active'] !== $isActive
                || (int) $stored['is_adult'] !== $isAdult
            ) {
                throw new RuntimeException(
                    'База даних не підтвердила збереження категорії.'
                );
            }

            $db->commit();
            Category::resetRuntimeCache();

            return true;
        } catch (Throwable $e) {
            self::rollBack($db);
            throw $e;
        }
    }


    /**
     * Move a whole subtree. The target department comes from the new parent;
     * only root moves may select a department explicitly.
     */
    public static function move(
        $categoryId,
        $targetParentId,
        $requestedDepartmentId
    ) {
        $categoryId = (int) $categoryId;
        $targetParentId = self::nullableId($targetParentId);
        $requestedDepartmentId = (int) $requestedDepartmentId;
        $db = Database::connect();

        try {
            $db->beginTransaction();
            $rows = self::lockTree($db);
            $byId = [];

            foreach ($rows as $row) {
                $byId[(int) $row['id']] = $row;
            }

            if (!isset($byId[$categoryId])) {
                throw new DomainException('Категорію не знайдено.');
            }

            $category = $byId[$categoryId];
            $oldDepartmentId = (int) $category['department_id'];
            $oldParentId = self::nullableId($category['parent_id'] ?? null);
            $subtreeIds = self::collectSubtreeIds($categoryId, $rows);

            if ($targetParentId !== null) {
                if (!isset($byId[$targetParentId])) {
                    throw new DomainException(
                        'Нову батьківську категорію не знайдено.'
                    );
                }

                if (in_array($targetParentId, $subtreeIds, true)) {
                    throw new DomainException(
                        'Категорію не можна перемістити всередину власної гілки.'
                    );
                }

                $targetDepartmentId = (int) $byId[$targetParentId]['department_id'];

                if (
                    $requestedDepartmentId > 0
                    && $requestedDepartmentId !== $targetDepartmentId
                ) {
                    throw new DomainException(
                        'Підрозділ визначається новою батьківською категорією.'
                    );
                }
            } else {
                if ($requestedDepartmentId <= 0) {
                    throw new DomainException(
                        'Оберіть підрозділ для кореневої категорії.'
                    );
                }

                $targetDepartmentId = $requestedDepartmentId;
            }

            self::lockDepartment($db, $targetDepartmentId);
            self::assertNoSlugCollisions(
                $db,
                $rows,
                $subtreeIds,
                $targetDepartmentId
            );

            $positionChanged = $targetDepartmentId !== $oldDepartmentId
                || $targetParentId !== $oldParentId;
            $newSortOrder = $positionChanged
                ? self::nextSortOrder(
                    $db,
                    $targetDepartmentId,
                    $targetParentId,
                    $categoryId
                )
                : (int) ($category['sort_order'] ?? 0);
            $idClause = self::idClause($subtreeIds, 'subtree');

            if ($targetDepartmentId !== $oldDepartmentId) {
                $params = ['department_id' => $targetDepartmentId]
                    + $idClause['params'];
                $stmt = $db->prepare("
                    UPDATE categories
                    SET department_id = :department_id
                    WHERE id IN ({$idClause['sql']})
                ");
                $stmt->execute($params);
            }

            $stmt = $db->prepare("
                UPDATE categories
                SET
                    parent_id = :parent_id,
                    sort_order = :sort_order
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $categoryId,
                'parent_id' => $targetParentId,
                'sort_order' => $newSortOrder
            ]);

            $verifyCategory = $db->prepare("
                SELECT department_id, parent_id
                FROM categories
                WHERE id = :id
                LIMIT 1
            ");
            $verifyCategory->execute(['id' => $categoryId]);
            $storedCategory = $verifyCategory->fetch(PDO::FETCH_ASSOC);

            if (
                !$storedCategory
                || (int) $storedCategory['department_id']
                    !== $targetDepartmentId
                || self::nullableId($storedCategory['parent_id'] ?? null)
                    !== $targetParentId
            ) {
                throw new RuntimeException(
                    'База даних не підтвердила нове положення категорії.'
                );
            }

            // Manager correctness does not depend on the legacy runtime
            // triggers: products are synchronized in this same transaction.
            $productParams = ['department_id' => $targetDepartmentId]
                + $idClause['params'];
            $stmt = $db->prepare("
                UPDATE products
                SET department_id = :department_id
                WHERE category_id IN ({$idClause['sql']})
            ");
            $stmt->execute($productParams);

            $verify = $db->prepare("
                SELECT COUNT(*)
                FROM products p
                INNER JOIN categories c
                    ON c.id = p.category_id
                WHERE p.category_id IN ({$idClause['sql']})
                  AND p.department_id <> c.department_id
            ");
            $verify->execute($idClause['params']);

            if ((int) $verify->fetchColumn() !== 0) {
                throw new RuntimeException(
                    'Не вдалося синхронізувати підрозділ усіх товарів гілки.'
                );
            }

            $db->commit();
            Category::resetRuntimeCache();

            return [
                'category_id' => $categoryId,
                'old_department_id' => $oldDepartmentId,
                'department_id' => $targetDepartmentId,
                'old_parent_id' => $oldParentId,
                'parent_id' => $targetParentId,
                'subtree_size' => count($subtreeIds)
            ];
        } catch (Throwable $e) {
            self::rollBack($db);
            throw $e;
        }
    }


    public static function reorder($categoryId, $direction)
    {
        $categoryId = (int) $categoryId;
        $direction = strtolower(trim((string) $direction));

        if (!in_array($direction, ['up', 'down'], true)) {
            throw new DomainException('Невідомий напрямок переміщення.');
        }

        $db = Database::connect();

        try {
            $db->beginTransaction();
            $rows = self::lockTree($db);
            $category = null;

            foreach ($rows as $row) {
                if ((int) $row['id'] === $categoryId) {
                    $category = $row;
                    break;
                }
            }

            if (!$category) {
                throw new DomainException('Категорію не знайдено.');
            }

            $siblings = [];
            $parentId = self::nullableId($category['parent_id'] ?? null);

            foreach ($rows as $row) {
                if (
                    (int) $row['department_id']
                        === (int) $category['department_id']
                    && self::nullableId($row['parent_id'] ?? null)
                        === $parentId
                ) {
                    $siblings[] = $row;
                }
            }

            usort($siblings, function ($left, $right) {
                $sort = (int) $left['sort_order']
                    <=> (int) $right['sort_order'];

                return $sort !== 0
                    ? $sort
                    : (int) $left['id'] <=> (int) $right['id'];
            });

            $currentIndex = null;

            foreach ($siblings as $index => $sibling) {
                if ((int) $sibling['id'] === $categoryId) {
                    $currentIndex = $index;
                    break;
                }
            }

            if ($currentIndex === null) {
                throw new RuntimeException(
                    'Категорію не знайдено серед її сусідів.'
                );
            }

            $targetIndex = $direction === 'up'
                ? $currentIndex - 1
                : $currentIndex + 1;

            if ($targetIndex < 0 || $targetIndex >= count($siblings)) {
                $db->commit();
                return false;
            }

            $temporary = $siblings[$targetIndex];
            $siblings[$targetIndex] = $siblings[$currentIndex];
            $siblings[$currentIndex] = $temporary;
            $update = $db->prepare("
                UPDATE categories
                SET sort_order = :sort_order
                WHERE id = :id
            ");

            foreach ($siblings as $index => $sibling) {
                $update->execute([
                    'id' => (int) $sibling['id'],
                    'sort_order' => ($index + 1) * 10
                ]);
            }

            $db->commit();
            Category::resetRuntimeCache();

            return true;
        } catch (Throwable $e) {
            self::rollBack($db);
            throw $e;
        }
    }


    public static function toggle($categoryId, $field, $value)
    {
        $categoryId = (int) $categoryId;
        $field = trim((string) $field);

        if (!in_array($field, ['is_active', 'is_adult'], true)) {
            throw new DomainException('Недозволене поле категорії.');
        }

        $value = !empty($value) ? 1 : 0;
        $db = Database::connect();

        try {
            $db->beginTransaction();
            self::lockCategory($db, $categoryId);
            $stmt = $db->prepare("
                UPDATE categories
                SET {$field} = :value
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $categoryId,
                'value' => $value
            ]);
            $db->commit();
            Category::resetRuntimeCache();

            return true;
        } catch (Throwable $e) {
            self::rollBack($db);
            throw $e;
        }
    }


    /**
     * Hard deletion is intentionally leaf-only and product-free.
     */
    public static function delete($categoryId)
    {
        $categoryId = (int) $categoryId;
        $db = Database::connect();

        try {
            CategoryTranslator::getForCategory(0);
            $db->beginTransaction();
            self::lockCategory($db, $categoryId);

            $children = $db->prepare("
                SELECT id
                FROM categories
                WHERE parent_id = :category_id
                FOR UPDATE
            ");
            $children->execute(['category_id' => $categoryId]);

            if ($children->fetchColumn() !== false) {
                throw new DomainException(
                    'Спочатку перемістіть або видаліть дочірні категорії.'
                );
            }

            $products = $db->prepare("
                SELECT id
                FROM products
                WHERE category_id = :category_id
                FOR UPDATE
            ");
            $products->execute(['category_id' => $categoryId]);

            if ($products->fetchColumn() !== false) {
                throw new DomainException(
                    'Категорію з товарами видалити не можна.'
                );
            }

            // Explicit deletion keeps the operation safe before and after the
            // new ON DELETE CASCADE translation constraint is installed.
            $translations = $db->prepare("
                DELETE FROM category_translations
                WHERE category_id = :category_id
            ");
            $translations->execute(['category_id' => $categoryId]);

            $delete = $db->prepare("
                DELETE FROM categories
                WHERE id = :category_id
            ");
            $delete->execute(['category_id' => $categoryId]);

            if ($delete->rowCount() !== 1) {
                throw new RuntimeException(
                    'База даних не підтвердила видалення категорії.'
                );
            }

            $db->commit();
            Category::resetRuntimeCache();

            return true;
        } catch (Throwable $e) {
            self::rollBack($db);
            throw $e;
        }
    }


    private static function saveTranslations(
        $categoryId,
        array $input,
        array $activeLanguages,
        array $before,
        $sourceChanged
    ) {
        $names = is_array($input['translation_name'] ?? null)
            ? $input['translation_name']
            : [];
        $descriptions = is_array($input['translation_description'] ?? null)
            ? $input['translation_description']
            : [];
        $sources = is_array($input['translation_source'] ?? null)
            ? $input['translation_source']
            : [];
        $statuses = is_array($input['translation_status'] ?? null)
            ? $input['translation_status']
            : [];
        $expected = [];

        foreach ($activeLanguages as $language) {
            $code = strtolower(trim((string) ($language['code'] ?? '')));

            if ($code === '' || $code === Language::SOURCE_CODE) {
                continue;
            }

            $name = trim((string) ($names[$code] ?? ''));
            $description = trim((string) ($descriptions[$code] ?? ''));
            $stored = is_array($before[$code] ?? null)
                ? $before[$code]
                : [];
            $source = TranslationWorkflow::normalizeSource(
                $sources[$code] ?? ($stored['source'] ?? 'manual')
            );
            $status = TranslationWorkflow::normalizeStatus(
                $statuses[$code] ?? ($stored['status'] ?? 'approved'),
                $name !== '' || $description !== ''
            );
            $translationChanged = TranslationWorkflow::translationChanged(
                $stored,
                $name,
                $description
            );
            $oldStatus = TranslationWorkflow::normalizeStatus(
                $stored['status'] ?? 'approved',
                !empty($stored)
            );

            if (
                $sourceChanged
                && !empty($stored)
                && !$translationChanged
                && $status === $oldStatus
            ) {
                $expected[$code] = [
                    'name' => (string) ($stored['name'] ?? ''),
                    'description' => (string) ($stored['description'] ?? ''),
                    'source' => (string) ($stored['source'] ?? 'manual'),
                    'status' => 'outdated'
                ];
                continue;
            }

            CategoryTranslator::saveForCategory(
                $categoryId,
                $code,
                $name,
                $description,
                $source,
                $status
            );
            $expected[$code] = [
                'name' => $name,
                'description' => $description,
                'source' => $source,
                'status' => $status
            ];
        }

        return $expected;
    }


    private static function verifyTranslations(array $stored, array $expected)
    {
        foreach ($expected as $code => $translation) {
            $name = trim((string) ($translation['name'] ?? ''));
            $description = trim(
                (string) ($translation['description'] ?? '')
            );

            if ($name === '' && $description === '') {
                if (isset($stored[$code])) {
                    throw new RuntimeException(
                        'Порожній переклад ' . strtoupper($code)
                        . ' не було видалено.'
                    );
                }

                continue;
            }

            $actual = $stored[$code] ?? null;

            if (
                !$actual
                || trim((string) ($actual['name'] ?? '')) !== $name
                || trim((string) ($actual['description'] ?? ''))
                    !== $description
                || (string) ($actual['source'] ?? '')
                    !== (string) ($translation['source'] ?? 'manual')
                || (string) ($actual['status'] ?? '')
                    !== (string) ($translation['status'] ?? 'approved')
            ) {
                throw new RuntimeException(
                    'База даних не підтвердила переклад '
                    . strtoupper($code) . '.'
                );
            }
        }
    }


    private static function lockTree(PDO $db)
    {
        return $db->query("
            SELECT
                id,
                department_id,
                parent_id,
                slug,
                sort_order
            FROM categories
            ORDER BY id ASC
            FOR UPDATE
        ")->fetchAll(PDO::FETCH_ASSOC);
    }


    private static function lockCategory(PDO $db, $categoryId)
    {
        $stmt = $db->prepare("
            SELECT *
            FROM categories
            WHERE id = :id
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute(['id' => (int) $categoryId]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$category) {
            throw new DomainException('Категорію не знайдено.');
        }

        return $category;
    }


    private static function lockDepartment(PDO $db, $departmentId)
    {
        $stmt = $db->prepare("
            SELECT id
            FROM departments
            WHERE id = :id
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute(['id' => (int) $departmentId]);

        if ($stmt->fetchColumn() === false) {
            throw new DomainException('Підрозділ не знайдено.');
        }
    }


    private static function collectSubtreeIds($rootId, array $rows)
    {
        $children = [];

        foreach ($rows as $row) {
            $parentId = (int) ($row['parent_id'] ?? 0);

            if ($parentId > 0) {
                $children[$parentId][] = (int) $row['id'];
            }
        }

        $ids = [];
        $queue = [(int) $rootId];

        while (!empty($queue)) {
            $id = array_shift($queue);

            if (isset($ids[$id])) {
                throw new DomainException(
                    'У дереві категорій виявлено цикл. Переміщення скасовано.'
                );
            }

            $ids[$id] = true;

            foreach ($children[$id] ?? [] as $childId) {
                $queue[] = $childId;
            }
        }

        return array_map('intval', array_keys($ids));
    }


    private static function assertNoSlugCollisions(
        PDO $db,
        array $rows,
        array $subtreeIds,
        $targetDepartmentId
    ) {
        $inside = array_fill_keys($subtreeIds, true);
        $movingSlugs = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $slug = strtolower(trim((string) $row['slug']));

            if (isset($inside[$id])) {
                if (isset($movingSlugs[$slug])) {
                    throw new DomainException(
                        'У гілці вже є повторюваний slug: ' . $slug . '.'
                    );
                }

                $movingSlugs[$slug] = true;
            }
        }

        $idClause = self::idClause($subtreeIds, 'collision');
        $stmt = $db->prepare("
            SELECT slug
            FROM categories
            WHERE department_id = :department_id
              AND slug = :slug
              AND id NOT IN ({$idClause['sql']})
            LIMIT 1
            FOR UPDATE
        ");
        $collisions = [];

        foreach (array_keys($movingSlugs) as $slug) {
            $stmt->execute([
                'department_id' => (int) $targetDepartmentId,
                'slug' => $slug
            ] + $idClause['params']);
            $storedSlug = $stmt->fetchColumn();

            if ($storedSlug !== false) {
                $collisions[] = (string) $storedSlug;
            }
        }

        if (!empty($collisions)) {
            throw new DomainException(
                'Переміщення створить конфлікт slug у цільовому підрозділі: '
                . implode(', ', array_unique($collisions)) . '.'
            );
        }
    }


    private static function uniqueSlug(PDO $db, $departmentId, $name)
    {
        $base = self::slugify($name);
        $stmt = $db->prepare("
            SELECT id
            FROM categories
            WHERE department_id = :department_id
              AND slug = :slug
            LIMIT 1
            FOR UPDATE
        ");
        $candidate = $base;
        $suffix = 2;

        while (true) {
            $stmt->execute([
                'department_id' => (int) $departmentId,
                'slug' => $candidate
            ]);

            if ($stmt->fetchColumn() === false) {
                return $candidate;
            }

            $tail = '-' . $suffix;
            $candidate = substr($base, 0, 150 - strlen($tail)) . $tail;
            $suffix++;
        }
    }


    private static function slugify($name)
    {
        $name = trim((string) $name);
        $map = [
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'H', 'Ґ' => 'G',
            'Д' => 'D', 'Е' => 'E', 'Є' => 'Ye', 'Ж' => 'Zh', 'З' => 'Z',
            'И' => 'Y', 'І' => 'I', 'Ї' => 'Yi', 'Й' => 'I', 'К' => 'K',
            'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O', 'П' => 'P',
            'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U', 'Ф' => 'F',
            'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch', 'Ш' => 'Sh',
            'Щ' => 'Shch', 'Ь' => '', 'Ю' => 'Yu', 'Я' => 'Ya',
            'Ё' => 'Yo', 'Ъ' => '', 'Ы' => 'Y', 'Э' => 'E',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g',
            'д' => 'd', 'е' => 'e', 'є' => 'ye', 'ж' => 'zh', 'з' => 'z',
            'и' => 'y', 'і' => 'i', 'ї' => 'yi', 'й' => 'i', 'к' => 'k',
            'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
            'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f',
            'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh',
            'щ' => 'shch', 'ь' => '', 'ю' => 'yu', 'я' => 'ya',
            'ё' => 'yo', 'ъ' => '', 'ы' => 'y', 'э' => 'e'
        ];
        $slug = strtr($name, $map);

        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);

            if (is_string($converted) && $converted !== '') {
                $slug = $converted;
            }
        }

        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim((string) $slug, '-');

        if ($slug === '') {
            $slug = 'category';
        }

        return substr($slug, 0, 150);
    }


    private static function nextSortOrder(
        PDO $db,
        $departmentId,
        $parentId,
        $excludeId = 0
    ) {
        $sql = "
            SELECT COALESCE(MAX(sort_order), 0)
            FROM categories
            WHERE department_id = :department_id
        ";
        $params = ['department_id' => (int) $departmentId];

        if ($parentId === null) {
            $sql .= ' AND parent_id IS NULL';
        } else {
            $sql .= ' AND parent_id = :parent_id';
            $params['parent_id'] = (int) $parentId;
        }

        if ((int) $excludeId > 0) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = (int) $excludeId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() + 10;
    }


    private static function idClause(array $ids, $prefix)
    {
        $placeholders = [];
        $params = [];

        foreach (array_values($ids) as $index => $id) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (int) $id;
        }

        if (empty($placeholders)) {
            throw new LogicException('Порожня гілка категорій.');
        }

        return [
            'sql' => implode(', ', $placeholders),
            'params' => $params
        ];
    }


    private static function requiredName($name)
    {
        $name = trim((string) $name);
        if (function_exists('mb_strlen')) {
            $length = mb_strlen($name, 'UTF-8');
        } elseif (preg_match_all('/./us', $name, $characters) !== false) {
            $length = count($characters[0]);
        } else {
            $length = strlen($name);
        }

        if ($name === '') {
            throw new DomainException('Назва категорії обов’язкова.');
        }

        if ($length > 150) {
            throw new DomainException(
                'Назва категорії не може бути довшою за 150 символів.'
            );
        }

        return $name;
    }


    private static function nullableText($value)
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }


    private static function nullableId($value)
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }


    private static function rollBack(PDO $db)
    {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}
