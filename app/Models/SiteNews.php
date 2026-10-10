<?php

class SiteNews
{
    private const STATUS_DRAFT = 'draft';
    private const STATUS_PUBLISHED = 'published';


    public static function latestPublished($limit, $languageCode)
    {
        $limit = max(1, min(20, (int) $limit));
        $items = Database::connect()->query("
            SELECT
                id,
                slug,
                title,
                summary,
                body,
                image_path,
                status,
                published_at,
                created_at,
                updated_at
            FROM site_news
            WHERE status = 'published'
              AND published_at IS NOT NULL
              AND published_at <= NOW()
            ORDER BY published_at DESC, id DESC
            LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC);

        return SiteNewsTranslator::localizeList(
            $items,
            $languageCode
        );
    }


    public static function publishedPage($languageCode)
    {
        $items = Database::connect()->query("
            SELECT
                id,
                slug,
                title,
                summary,
                body,
                image_path,
                status,
                published_at,
                created_at,
                updated_at
            FROM site_news
            WHERE status = 'published'
              AND published_at IS NOT NULL
              AND published_at <= NOW()
            ORDER BY published_at DESC, id DESC
            LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);

        return SiteNewsTranslator::localizeList(
            $items,
            $languageCode
        );
    }


    public static function findPublishedBySlug($slug, $languageCode)
    {
        $slug = trim((string) $slug);

        if ($slug === '') {
            return null;
        }

        $stmt = Database::connect()->prepare("
            SELECT
                id,
                slug,
                title,
                summary,
                body,
                image_path,
                status,
                published_at,
                created_at,
                updated_at
            FROM site_news
            WHERE slug = :slug
              AND status = 'published'
              AND published_at IS NOT NULL
              AND published_at <= NOW()
            LIMIT 1
        ");
        $stmt->execute(['slug' => $slug]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            return null;
        }

        return SiteNewsTranslator::localize(
            $item,
            $languageCode
        );
    }


    public static function findById($newsId)
    {
        $stmt = Database::connect()->prepare("
            SELECT
                id,
                slug,
                title,
                summary,
                body,
                image_path,
                status,
                published_at,
                created_at,
                updated_at
            FROM site_news
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => (int) $newsId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        return $item ?: null;
    }


    public static function adminAll()
    {
        return Database::connect()->query("
            SELECT
                id,
                slug,
                title,
                summary,
                body,
                image_path,
                status,
                published_at,
                created_at,
                updated_at
            FROM site_news
            ORDER BY created_at DESC, id DESC
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function createDraft(array $input)
    {
        $title = self::normalizeRequiredText(
            $input['title'] ?? '',
            'Вкажіть заголовок новини.',
            255
        );
        $body = self::normalizeRequiredText(
            $input['body'] ?? '',
            'Вкажіть текст новини.',
            50000
        );
        $summary = self::normalizeOptionalText(
            $input['summary'] ?? '',
            2000
        );
        $imagePath = self::normalizeOptionalText(
            $input['image_path'] ?? '',
            500
        );
        $publishedAt = self::normalizeDateTime(
            $input['published_at'] ?? ''
        );
        $slug = self::uniqueSlug(self::makeSlug($title));

        $stmt = Database::connect()->prepare("
            INSERT INTO site_news
            (
                slug,
                title,
                summary,
                body,
                image_path,
                status,
                published_at
            )
            VALUES
            (
                :slug,
                :title,
                :summary,
                :body,
                :image_path,
                'draft',
                :published_at
            )
        ");
        $stmt->execute([
            'slug' => $slug,
            'title' => $title,
            'summary' => $summary,
            'body' => $body,
            'image_path' => $imagePath,
            'published_at' => $publishedAt
        ]);

        return (int) Database::connect()->lastInsertId();
    }


    public static function update($newsId, array $input)
    {
        $newsId = (int) $newsId;

        if ($newsId <= 0 || !self::findById($newsId)) {
            throw new InvalidArgumentException('Новину не знайдено.');
        }

        $title = self::normalizeRequiredText(
            $input['title'] ?? '',
            'Вкажіть заголовок новини.',
            255
        );
        $body = self::normalizeRequiredText(
            $input['body'] ?? '',
            'Вкажіть текст новини.',
            50000
        );
        $summary = self::normalizeOptionalText(
            $input['summary'] ?? '',
            2000
        );
        $imagePath = self::normalizeOptionalText(
            $input['image_path'] ?? '',
            500
        );
        $publishedAt = self::normalizeDateTime(
            $input['published_at'] ?? ''
        );

        $stmt = Database::connect()->prepare("
            UPDATE site_news
            SET title = :title,
                summary = :summary,
                body = :body,
                image_path = :image_path,
                published_at = :published_at
            WHERE id = :id
        ");
        $stmt->execute([
            'title' => $title,
            'summary' => $summary,
            'body' => $body,
            'image_path' => $imagePath,
            'published_at' => $publishedAt,
            'id' => $newsId
        ]);
    }


    public static function setPublished($newsId, $published)
    {
        $newsId = (int) $newsId;

        if ($newsId <= 0 || !self::findById($newsId)) {
            throw new InvalidArgumentException('Новину не знайдено.');
        }

        if ($published) {
            $stmt = Database::connect()->prepare("
                UPDATE site_news
                SET status = 'published',
                    published_at = COALESCE(published_at, NOW())
                WHERE id = :id
            ");
        } else {
            $stmt = Database::connect()->prepare("
                UPDATE site_news
                SET status = 'draft'
                WHERE id = :id
            ");
        }

        $stmt->execute(['id' => $newsId]);
    }


    public static function delete($newsId)
    {
        $newsId = (int) $newsId;

        if ($newsId <= 0) {
            throw new InvalidArgumentException('Некоректна новина.');
        }

        $stmt = Database::connect()->prepare("
            DELETE FROM site_news
            WHERE id = :id
        ");
        $stmt->execute(['id' => $newsId]);
    }


    private static function makeSlug($text)
    {
        $text = trim((string) $text);
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'h','ґ'=>'g','д'=>'d','е'=>'e','є'=>'ye',
            'ж'=>'zh','з'=>'z','и'=>'y','і'=>'i','ї'=>'yi','й'=>'y','к'=>'k','л'=>'l',
            'м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u',
            'ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch','ь'=>'',
            'ю'=>'yu','я'=>'ya','ы'=>'y','э'=>'e','ъ'=>'',
            'А'=>'a','Б'=>'b','В'=>'v','Г'=>'h','Ґ'=>'g','Д'=>'d','Е'=>'e','Є'=>'ye',
            'Ж'=>'zh','З'=>'z','И'=>'y','І'=>'i','Ї'=>'yi','Й'=>'y','К'=>'k','Л'=>'l',
            'М'=>'m','Н'=>'n','О'=>'o','П'=>'p','Р'=>'r','С'=>'s','Т'=>'t','У'=>'u',
            'Ф'=>'f','Х'=>'kh','Ц'=>'ts','Ч'=>'ch','Ш'=>'sh','Щ'=>'shch','Ь'=>'',
            'Ю'=>'yu','Я'=>'ya','Ы'=>'y','Э'=>'e','Ъ'=>''
        ];
        $text = strtr($text, $map);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim((string) $text, '-');

        return $text !== '' ? substr($text, 0, 170) : 'news';
    }


    private static function uniqueSlug($base)
    {
        $base = trim((string) $base, '-');
        $candidate = $base !== '' ? $base : 'news';
        $suffix = 2;
        $db = Database::connect();

        while (true) {
            $stmt = $db->prepare("
                SELECT 1
                FROM site_news
                WHERE slug = :slug
                LIMIT 1
            ");
            $stmt->execute(['slug' => $candidate]);

            if (!$stmt->fetchColumn()) {
                return $candidate;
            }

            $candidate = substr($base, 0, 160)
                . '-'
                . $suffix;
            $suffix++;
        }
    }


    private static function normalizeRequiredText($value, $message, $maxLength)
    {
        $value = trim((string) $value);
        $length = function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);

        if ($value === '') {
            throw new InvalidArgumentException((string) $message);
        }

        if ($length > (int) $maxLength) {
            throw new InvalidArgumentException('Текст перевищує допустиму довжину.');
        }

        return $value;
    }


    private static function normalizeOptionalText($value, $maxLength)
    {
        $value = trim((string) $value);
        $length = function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);

        if ($length > (int) $maxLength) {
            throw new InvalidArgumentException('Текст перевищує допустиму довжину.');
        }

        return $value !== '' ? $value : null;
    }


    private static function normalizeDateTime($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            throw new InvalidArgumentException('Некоректна дата публікації.');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
