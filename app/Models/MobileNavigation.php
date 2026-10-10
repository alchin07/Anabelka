<?php

class MobileNavigation
{
    private const MAX_NAME_LENGTH = 160;
    private const MAX_URL_LENGTH = 1000;


    public static function validateUrl($input)
    {
        if (!is_string($input)) {
            throw new InvalidArgumentException('Некоректне посилання.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $input)) {
            throw new InvalidArgumentException('Посилання містить недопустимі символи.');
        }

        $url = trim($input);

        if ($url === '' || self::stringLength($url) > self::MAX_URL_LENGTH) {
            throw new InvalidArgumentException('Некоректне посилання.');
        }

        if (strpos($url, '\\') !== false || strpos($url, '//') === 0) {
            throw new InvalidArgumentException('Некоректне посилання.');
        }

        if (preg_match('/^(?:javascript|data|file):/i', $url)) {
            throw new InvalidArgumentException('Недозволена схема посилання.');
        }

        if (preg_match('#^https?://#i', $url)) {
            $parts = parse_url($url);

            if (!is_array($parts)) {
                throw new InvalidArgumentException('Некоректне зовнішнє посилання.');
            }

            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = trim((string) ($parts['host'] ?? ''));

            if (
                !in_array($scheme, ['http', 'https'], true)
                || $host === ''
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                throw new InvalidArgumentException('Некоректне зовнішнє посилання.');
            }

            return [
                'url' => $url,
                'is_external' => true
            ];
        }

        $parts = parse_url($url);

        if (
            !is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new InvalidArgumentException('Некоректне внутрішнє посилання.');
        }

        $path = (string) ($parts['path'] ?? '');
        $decodedOnce = rawurldecode($path);
        $decodedTwice = rawurldecode($decodedOnce);

        if ($decodedTwice !== $decodedOnce) {
            throw new InvalidArgumentException('Неоднозначне кодування посилання.');
        }

        if (strpos($decodedOnce, '\\') !== false) {
            throw new InvalidArgumentException('Некоректне внутрішнє посилання.');
        }

        if (
            $decodedOnce !== '/Anabelka'
            && strpos($decodedOnce, '/Anabelka/') !== 0
        ) {
            throw new InvalidArgumentException(
                'Внутрішнє посилання повинно вести в Анабельку.'
            );
        }

        $segments = explode('/', $decodedOnce);

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException(
                    'Шлях не може виходити за межі Анабельки.'
                );
            }
        }

        return [
            'url' => $url,
            'is_external' => false
        ];
    }


    public static function publicItems($languageCode)
    {
        try {
            $stmt = Database::connect()->query("
                SELECT
                    id,
                    name_uk,
                    url,
                    is_external,
                    is_active,
                    sort_order
                FROM mobile_navigation_items
                WHERE is_active = 1
                ORDER BY sort_order ASC, id ASC
            ");
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $index => $item) {
                try {
                    $validated = self::validateUrl($item['url'] ?? '');
                } catch (Throwable $e) {
                    unset($items[$index]);
                    continue;
                }

                $items[$index]['id'] = (int) ($item['id'] ?? 0);
                $items[$index]['url'] = $validated['url'];
                $items[$index]['is_external'] = $validated['is_external'];
                $items[$index]['is_active'] = 1;
                $items[$index]['sort_order'] = (int) ($item['sort_order'] ?? 0);
            }

            $items = array_values($items);

            return MobileNavigationTranslator::localizeList(
                $items,
                (string) $languageCode
            );
        } catch (Throwable $e) {
            error_log('Mobile navigation public read: ' . $e->getMessage());

            return self::fallbackItems();
        }
    }


    public static function adminItems()
    {
        $db = Database::connect();
        $items = $db->query("
            SELECT
                id,
                name_uk,
                url,
                is_external,
                is_active,
                sort_order,
                created_at,
                updated_at
            FROM mobile_navigation_items
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $ids = [];

        foreach ($items as &$item) {
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['is_external'] = !empty($item['is_external']);
            $item['is_active'] = !empty($item['is_active']);
            $item['sort_order'] = (int) ($item['sort_order'] ?? 0);
            $item['translations'] = [];
            $ids[] = $item['id'];
        }
        unset($item);

        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("
                SELECT
                    item_id,
                    language_code,
                    name,
                    source,
                    status
                FROM mobile_navigation_item_translations
                WHERE item_id IN ({$placeholders})
                ORDER BY item_id ASC, language_code ASC
            ");
            $stmt->execute($ids);
            $byId = [];

            foreach ($items as $index => $item) {
                $byId[(int) $item['id']] = $index;
            }

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $translation) {
                $itemId = (int) ($translation['item_id'] ?? 0);

                if (!isset($byId[$itemId])) {
                    continue;
                }

                $items[$byId[$itemId]]['translations'][
                    (string) ($translation['language_code'] ?? '')
                ] = $translation;
            }
        }

        return [
            'items' => $items,
            'revision' => self::orderRevision($ids)
        ];
    }


    public static function create(array $input)
    {
        $name = self::normalizeName($input['name_uk'] ?? $input['name'] ?? '');
        $validatedUrl = self::validateUrl($input['url'] ?? '');
        $isActive = array_key_exists('is_active', $input)
            ? self::normalizeBoolean($input['is_active'])
            : true;
        $preparedTranslations = MobileNavigationTranslator::prepareSubmitted(
            is_array($input['translations'] ?? null)
                ? $input['translations']
                : []
        );
        $db = Database::connect();
        $db->beginTransaction();

        try {
            $maxOrder = (int) $db->query("
                SELECT COALESCE(MAX(sort_order), 0)
                FROM mobile_navigation_items
                FOR UPDATE
            ")->fetchColumn();
            $stmt = $db->prepare("
                INSERT INTO mobile_navigation_items
                (
                    name_uk,
                    url,
                    is_external,
                    is_active,
                    sort_order
                )
                VALUES
                (
                    :name_uk,
                    :url,
                    :is_external,
                    :is_active,
                    :sort_order
                )
            ");
            $stmt->execute([
                'name_uk' => $name,
                'url' => $validatedUrl['url'],
                'is_external' => $validatedUrl['is_external'] ? 1 : 0,
                'is_active' => $isActive ? 1 : 0,
                'sort_order' => $maxOrder + 10
            ]);
            $itemId = (int) $db->lastInsertId();

            if (!empty($preparedTranslations)) {
                MobileNavigationTranslator::savePrepared(
                    $db,
                    $itemId,
                    $preparedTranslations
                );
            }

            $db->commit();

            return $itemId;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function update($itemId, array $input)
    {
        $itemId = self::normalizeId($itemId);
        $name = self::normalizeName($input['name_uk'] ?? $input['name'] ?? '');
        $validatedUrl = self::validateUrl($input['url'] ?? '');
        $preparedTranslations = MobileNavigationTranslator::prepareSubmitted(
            is_array($input['translations'] ?? null)
                ? $input['translations']
                : []
        );
        $db = Database::connect();
        $db->beginTransaction();

        try {
            $select = $db->prepare("
                SELECT name_uk, is_active
                FROM mobile_navigation_items
                WHERE id = :id
                FOR UPDATE
            ");
            $select->execute(['id' => $itemId]);
            $current = $select->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                throw new RuntimeException('Пункт мобільного меню не знайдено.');
            }

            $isActive = array_key_exists('is_active', $input)
                ? self::normalizeBoolean($input['is_active'])
                : !empty($current['is_active']);
            $stmt = $db->prepare("
                UPDATE mobile_navigation_items
                SET
                    name_uk = :name_uk,
                    url = :url,
                    is_external = :is_external,
                    is_active = :is_active
                WHERE id = :id
            ");
            $stmt->execute([
                'name_uk' => $name,
                'url' => $validatedUrl['url'],
                'is_external' => $validatedUrl['is_external'] ? 1 : 0,
                'is_active' => $isActive ? 1 : 0,
                'id' => $itemId
            ]);

            if (trim((string) $current['name_uk']) !== $name) {
                MobileNavigationTranslator::markOutdatedUsingConnection(
                    $db,
                    $itemId
                );
            }

            if (!empty($preparedTranslations)) {
                MobileNavigationTranslator::savePrepared(
                    $db,
                    $itemId,
                    $preparedTranslations
                );
            }

            $db->commit();

            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function setActive($itemId, $active)
    {
        $itemId = self::normalizeId($itemId);
        $stmt = Database::connect()->prepare("
            UPDATE mobile_navigation_items
            SET is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            'is_active' => self::normalizeBoolean($active) ? 1 : 0,
            'id' => $itemId
        ]);

        if ($stmt->rowCount() === 0) {
            $check = Database::connect()->prepare("
                SELECT 1
                FROM mobile_navigation_items
                WHERE id = :id
                LIMIT 1
            ");
            $check->execute(['id' => $itemId]);

            if (!$check->fetchColumn()) {
                throw new RuntimeException('Пункт мобільного меню не знайдено.');
            }
        }

        return true;
    }


    public static function reorder(array $ids, $revision)
    {
        $ids = array_values(array_map('intval', $ids));

        if (
            empty($ids)
            || count($ids) !== count(array_unique($ids))
            || min($ids) <= 0
        ) {
            throw new InvalidArgumentException('Некоректний порядок меню.');
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $rows = $db->query("
                SELECT id
                FROM mobile_navigation_items
                ORDER BY sort_order ASC, id ASC
                FOR UPDATE
            ")->fetchAll(PDO::FETCH_COLUMN);
            $currentIds = array_map('intval', $rows);

            if (
                count($currentIds) !== count($ids)
                || array_diff($currentIds, $ids)
                || array_diff($ids, $currentIds)
            ) {
                throw new RuntimeException('Список меню змінився. Оновіть сторінку.');
            }

            $currentRevision = self::orderRevision($currentIds);

            if (!hash_equals($currentRevision, (string) $revision)) {
                throw new RuntimeException('Порядок меню вже змінився. Оновіть сторінку.');
            }

            $update = $db->prepare("
                UPDATE mobile_navigation_items
                SET sort_order = :sort_order
                WHERE id = :id
            ");

            foreach ($ids as $index => $id) {
                $update->execute([
                    'sort_order' => ($index + 1) * 10,
                    'id' => $id
                ]);
            }

            $db->commit();

            return self::orderRevision($ids);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function delete($itemId)
    {
        $itemId = self::normalizeId($itemId);
        $stmt = Database::connect()->prepare("
            DELETE FROM mobile_navigation_items
            WHERE id = :id
        ");
        $stmt->execute(['id' => $itemId]);

        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Пункт мобільного меню не знайдено.');
        }

        return true;
    }


    private static function fallbackItems()
    {
        return [
            [
                'id' => 0,
                'name_uk' => 'Знижки',
                'name' => 'Знижки',
                'url' => '/Anabelka/discounts',
                'is_external' => false
            ],
            [
                'id' => 0,
                'name_uk' => 'Новинки',
                'name' => 'Новинки',
                'url' => '/Anabelka/new',
                'is_external' => false
            ],
            [
                'id' => 0,
                'name_uk' => 'Доставка, оплата і повернення',
                'name' => 'Доставка, оплата і повернення',
                'url' => '/Anabelka/delivery-payment',
                'is_external' => false
            ],
            [
                'id' => 0,
                'name_uk' => 'Контакти',
                'name' => 'Контакти',
                'url' => '/Anabelka/contacts',
                'is_external' => false
            ]
        ];
    }


    private static function normalizeId($itemId)
    {
        $itemId = (int) $itemId;

        if ($itemId <= 0) {
            throw new InvalidArgumentException('Некоректний пункт мобільного меню.');
        }

        return $itemId;
    }


    private static function normalizeName($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            throw new InvalidArgumentException('Укажіть назву пункту меню.');
        }

        if (self::stringLength($value) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException('Назва пункту меню занадто довга.');
        }

        return $value;
    }


    private static function normalizeBoolean($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }


    private static function orderRevision(array $ids)
    {
        return sha1(implode(',', array_map('intval', $ids)));
    }


    private static function stringLength($value)
    {
        return function_exists('mb_strlen')
            ? mb_strlen((string) $value, 'UTF-8')
            : strlen((string) $value);
    }
}
