<?php

class SiteNewsTranslator
{
    public static function localize(array $news, $languageCode)
    {
        $languageCode = strtolower(trim((string) $languageCode));
        $newsId = (int) ($news['id'] ?? 0);

        if (
            $newsId <= 0
            || $languageCode === ''
            || $languageCode === Language::SOURCE_CODE
        ) {
            return $news;
        }

        $stmt = Database::connect()->prepare("
            SELECT
                title,
                summary,
                body
            FROM site_news_translations
            WHERE news_id = :news_id
              AND language_code = :language_code
              AND status IN ('approved', 'outdated')
            LIMIT 1
        ");
        $stmt->execute([
            'news_id' => $newsId,
            'language_code' => $languageCode
        ]);
        $translation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$translation) {
            return $news;
        }

        $news['title'] = (string) ($translation['title'] ?? $news['title'] ?? '');
        $news['summary'] = $translation['summary'] ?? ($news['summary'] ?? null);
        $news['body'] = (string) ($translation['body'] ?? $news['body'] ?? '');

        return $news;
    }


    public static function localizeList(array $items, $languageCode)
    {
        $languageCode = strtolower(trim((string) $languageCode));

        if (
            empty($items)
            || $languageCode === ''
            || $languageCode === Language::SOURCE_CODE
        ) {
            return $items;
        }

        $ids = [];

        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if (empty($ids)) {
            return $items;
        }

        $translations = self::getForNewsIds(
            array_values($ids),
            $languageCode
        );

        foreach ($items as &$item) {
            $id = (int) ($item['id'] ?? 0);
            $translation = $translations[$id] ?? null;

            if (!is_array($translation)) {
                continue;
            }

            $item['title'] = (string) ($translation['title'] ?? $item['title'] ?? '');
            $item['summary'] = $translation['summary'] ?? ($item['summary'] ?? null);
            $item['body'] = (string) ($translation['body'] ?? $item['body'] ?? '');
        }
        unset($item);

        return $items;
    }


    public static function save($newsId, $languageCode, array $input)
    {
        $newsId = (int) $newsId;
        $languageCode = strtolower(trim((string) $languageCode));

        if ($newsId <= 0 || $languageCode === '') {
            throw new InvalidArgumentException('Некоректний переклад новини.');
        }

        if ($languageCode === Language::SOURCE_CODE) {
            return;
        }

        $language = Language::findByCode($languageCode);
        if (!$language || empty($language['is_active'])) {
            throw new InvalidArgumentException('Мова недоступна.');
        }

        $title = trim((string) ($input['title'] ?? ''));
        $summary = trim((string) ($input['summary'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $source = TranslationWorkflow::normalizeSource(
            $input['source'] ?? 'manual'
        );
        $status = TranslationWorkflow::normalizeStatus(
            $input['status'] ?? 'approved',
            true
        );

        if ($title === '' && $summary === '' && $body === '') {
            $stmt = Database::connect()->prepare("
                DELETE FROM site_news_translations
                WHERE news_id = :news_id
                  AND language_code = :language_code
            ");
            $stmt->execute([
                'news_id' => $newsId,
                'language_code' => $languageCode
            ]);
            return;
        }

        if ($title === '' || $body === '') {
            throw new InvalidArgumentException(
                'Для перекладу потрібні заголовок і текст.'
            );
        }

        $stmt = Database::connect()->prepare("
            INSERT INTO site_news_translations
            (
                news_id,
                language_code,
                title,
                summary,
                body,
                source,
                status
            )
            VALUES
            (
                :news_id,
                :language_code,
                :title,
                :summary,
                :body,
                :source,
                :status
            )
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                summary = VALUES(summary),
                body = VALUES(body),
                source = VALUES(source),
                status = VALUES(status)
        ");
        $stmt->execute([
            'news_id' => $newsId,
            'language_code' => $languageCode,
            'title' => $title,
            'summary' => $summary !== '' ? $summary : null,
            'body' => $body,
            'source' => $source,
            'status' => $status
        ]);
    }


    public static function allForNews($newsId)
    {
        $stmt = Database::connect()->prepare("
            SELECT
                language_code,
                title,
                summary,
                body,
                source,
                status
            FROM site_news_translations
            WHERE news_id = :news_id
            ORDER BY language_code ASC
        ");
        $stmt->execute(['news_id' => (int) $newsId]);
        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['language_code']] = $row;
        }

        return $result;
    }


    private static function getForNewsIds(array $newsIds, $languageCode)
    {
        $newsIds = array_values(array_unique(array_filter(
            array_map('intval', $newsIds),
            static fn($id) => $id > 0
        )));

        if (empty($newsIds)) {
            return [];
        }

        $placeholders = [];
        $params = ['language_code' => (string) $languageCode];

        foreach ($newsIds as $index => $newsId) {
            $key = 'news_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $newsId;
        }

        $stmt = Database::connect()->prepare("
            SELECT
                news_id,
                title,
                summary,
                body
            FROM site_news_translations
            WHERE news_id IN (" . implode(', ', $placeholders) . ")
              AND language_code = :language_code
              AND status IN ('approved', 'outdated')
            ORDER BY news_id ASC
        ");
        $stmt->execute($params);
        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['news_id']] = $row;
        }

        return $result;
    }
}
