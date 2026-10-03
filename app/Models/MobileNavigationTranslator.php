<?php

class MobileNavigationTranslator
{
    public static function localizeList(array $items, $languageCode)
    {
        $languageCode = strtolower(trim((string) $languageCode));

        foreach ($items as $index => $item) {
            $items[$index]['name'] = (string) ($item['name_uk'] ?? '');
        }

        if (
            $languageCode === ''
            || $languageCode === Language::SOURCE_CODE
            || empty($items)
        ) {
            return $items;
        }

        $ids = array_values(array_unique(array_filter(
            array_map(
                static function ($item) {
                    return (int) ($item['id'] ?? 0);
                },
                $items
            ),
            static function ($id) {
                return $id > 0;
            }
        )));

        if (empty($ids)) {
            return $items;
        }

        $db = Database::connect();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            SELECT
                item_id,
                name,
                status
            FROM mobile_navigation_item_translations
            WHERE item_id IN ({$placeholders})
              AND language_code = ?
              AND status IN ('approved', 'outdated')
        ");
        $stmt->execute(array_merge($ids, [$languageCode]));
        $localized = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name !== '') {
                $localized[(int) $row['item_id']] = $name;
            }
        }

        foreach ($items as $index => $item) {
            $id = (int) ($item['id'] ?? 0);

            if (isset($localized[$id])) {
                $items[$index]['name'] = $localized[$id];
            }
        }

        return $items;
    }


    public static function getForItem($itemId)
    {
        $itemId = (int) $itemId;

        if ($itemId <= 0) {
            return [];
        }

        $stmt = Database::connect()->prepare("
            SELECT
                language_code,
                name,
                source,
                status
            FROM mobile_navigation_item_translations
            WHERE item_id = :item_id
            ORDER BY language_code ASC
        ");
        $stmt->execute(['item_id' => $itemId]);
        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['language_code']] = $row;
        }

        return $result;
    }


    public static function save($itemId, $languageCode, array $input)
    {
        $prepared = self::prepareSubmitted([
            $languageCode => $input
        ]);
        $languageCode = strtolower(trim((string) $languageCode));

        if (!isset($prepared[$languageCode])) {
            return true;
        }

        $db = Database::connect();
        self::savePrepared($db, (int) $itemId, $prepared);

        return true;
    }


    public static function markOutdated($itemId)
    {
        $itemId = (int) $itemId;

        if ($itemId <= 0) {
            return false;
        }

        $stmt = Database::connect()->prepare("
            UPDATE mobile_navigation_item_translations
            SET status = 'outdated'
            WHERE item_id = :item_id
              AND TRIM(name) <> ''
        ");

        return $stmt->execute(['item_id' => $itemId]);
    }


    public static function prepareSubmitted(array $translations)
    {
        if (empty($translations)) {
            return [];
        }

        $activeCodes = [];

        foreach (Language::active() as $language) {
            $code = strtolower(trim((string) ($language['code'] ?? '')));

            if ($code !== '') {
                $activeCodes[$code] = true;
            }
        }

        $prepared = [];

        foreach ($translations as $code => $input) {
            $code = strtolower(trim((string) $code));

            if (
                $code === ''
                || $code === Language::SOURCE_CODE
                || !isset($activeCodes[$code])
            ) {
                continue;
            }

            if (!is_array($input)) {
                $input = ['name' => $input];
            }

            $name = self::normalizeName($input['name'] ?? '', true);
            $prepared[$code] = [
                'name' => $name,
                'source' => TranslationWorkflow::normalizeSource(
                    $input['source'] ?? 'manual'
                ),
                'status' => TranslationWorkflow::normalizeStatus(
                    $input['status'] ?? 'approved',
                    $name !== ''
                )
            ];
        }

        return $prepared;
    }


    public static function savePrepared(
        PDO $db,
        $itemId,
        array $prepared
    ) {
        $itemId = (int) $itemId;

        if ($itemId <= 0) {
            throw new InvalidArgumentException(
                'Некоректний пункт мобільного меню.'
            );
        }

        $delete = $db->prepare("
            DELETE FROM mobile_navigation_item_translations
            WHERE item_id = :item_id
              AND language_code = :language_code
        ");
        $upsert = $db->prepare("
            INSERT INTO mobile_navigation_item_translations
            (
                item_id,
                language_code,
                name,
                source,
                status
            )
            VALUES
            (
                :item_id,
                :language_code,
                :name,
                :source,
                :status
            )
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                source = VALUES(source),
                status = VALUES(status)
        ");

        foreach ($prepared as $code => $translation) {
            $name = trim((string) ($translation['name'] ?? ''));

            if ($name === '') {
                $delete->execute([
                    'item_id' => $itemId,
                    'language_code' => (string) $code
                ]);
                continue;
            }

            $upsert->execute([
                'item_id' => $itemId,
                'language_code' => (string) $code,
                'name' => $name,
                'source' => TranslationWorkflow::normalizeSource(
                    $translation['source'] ?? 'manual'
                ),
                'status' => TranslationWorkflow::normalizeStatus(
                    $translation['status'] ?? 'approved',
                    true
                )
            ]);
        }
    }


    public static function markOutdatedUsingConnection(PDO $db, $itemId)
    {
        $stmt = $db->prepare("
            UPDATE mobile_navigation_item_translations
            SET status = 'outdated'
            WHERE item_id = :item_id
              AND TRIM(name) <> ''
        ");
        $stmt->execute(['item_id' => (int) $itemId]);
    }


    private static function normalizeName($value, $allowEmpty = false)
    {
        $value = trim((string) $value);

        if (!$allowEmpty && $value === '') {
            throw new InvalidArgumentException('Укажіть назву перекладу.');
        }

        $length = function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);

        if ($length > 160) {
            throw new InvalidArgumentException(
                'Назва перекладу занадто довга.'
            );
        }

        return $value;
    }
}
