<?php

class AdminDashboardLayout
{
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_dashboard_blocks
            (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(120) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_admin_dashboard_blocks_order
                    (is_active, sort_order, id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_dashboard_links
            (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                block_id INT UNSIGNED NOT NULL,
                service_key VARCHAR(80) NOT NULL,
                label_override VARCHAR(120) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_admin_dashboard_links_block_service
                    (block_id, service_key),
                KEY idx_admin_dashboard_links_block_order
                    (block_id, is_active, sort_order, id),
                KEY idx_admin_dashboard_links_service
                    (service_key),
                CONSTRAINT fk_admin_dashboard_links_block
                    FOREIGN KEY (block_id)
                    REFERENCES admin_dashboard_blocks(id)
                    ON DELETE CASCADE
                    ON UPDATE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function allForAdmin()
    {
        self::ensureSchema();

        $blocks = Database::connect()->query("
            SELECT id, title, is_active, sort_order
            FROM admin_dashboard_blocks
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $links = Database::connect()->query("
            SELECT
                id,
                block_id,
                service_key,
                label_override,
                is_active,
                sort_order
            FROM admin_dashboard_links
            ORDER BY block_id ASC, sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $linksByBlock = [];

        foreach ($links as $link) {
            $blockId = (int) ($link['block_id'] ?? 0);
            $serviceKey = (string) ($link['service_key'] ?? '');
            $service = class_exists('AdminDashboardServiceRegistry')
                ? AdminDashboardServiceRegistry::find($serviceKey)
                : null;

            $link['id'] = (int) ($link['id'] ?? 0);
            $link['block_id'] = $blockId;
            $link['sort_order'] = (int) ($link['sort_order'] ?? 0);
            $link['is_active'] = !empty($link['is_active']) ? 1 : 0;
            $link['service'] = $service;
            $link['display_label'] = trim(
                (string) ($link['label_override'] ?? '')
            );

            if ($link['display_label'] === '' && is_array($service)) {
                $link['display_label'] = (string) (
                    $service['label'] ?? $serviceKey
                );
            }

            if (!isset($linksByBlock[$blockId])) {
                $linksByBlock[$blockId] = [];
            }

            $linksByBlock[$blockId][] = $link;
        }

        foreach ($blocks as &$block) {
            $blockId = (int) ($block['id'] ?? 0);
            $block['id'] = $blockId;
            $block['sort_order'] = (int) ($block['sort_order'] ?? 0);
            $block['is_active'] = !empty($block['is_active']) ? 1 : 0;
            $block['links'] = $linksByBlock[$blockId] ?? [];
        }
        unset($block);

        return $blocks;
    }


    public static function activeForCurrentAdmin()
    {
        self::ensureSchema();

        $services = class_exists('AdminDashboardServiceRegistry')
            ? AdminDashboardServiceRegistry::availableWithBadges()
            : [];

        if (empty($services)) {
            return [];
        }

        $blocks = self::allForAdmin();
        $result = [];

        foreach ($blocks as $block) {
            if (empty($block['is_active'])) {
                continue;
            }

            $links = [];

            foreach ($block['links'] ?? [] as $link) {
                if (empty($link['is_active'])) {
                    continue;
                }

                $serviceKey = (string) ($link['service_key'] ?? '');

                if (!isset($services[$serviceKey])) {
                    continue;
                }

                $service = $services[$serviceKey];
                $link['service'] = $service;
                $link['url'] = (string) ($service['url'] ?? '');
                $link['badge_count'] = max(
                    0,
                    (int) ($service['badge_count'] ?? 0)
                );
                $link['badge_tone'] = (string) (
                    $service['badge']['tone']
                    ?? 'notification'
                );
                $links[] = $link;
            }

            if (empty($links)) {
                continue;
            }

            $block['links'] = $links;
            $result[] = $block;
        }

        return $result;
    }


    public static function createBlock($title)
    {
        self::ensureSchema();
        $title = self::normalizeTitle($title);
        $db = Database::connect();
        $db->beginTransaction();

        try {
            $last = $db->query("
                SELECT id, sort_order
                FROM admin_dashboard_blocks
                ORDER BY sort_order DESC, id DESC
                LIMIT 1
                FOR UPDATE
            ")->fetch(PDO::FETCH_ASSOC);
            $sortOrder = $last
                ? (int) ($last['sort_order'] ?? 0) + 10
                : 10;

            $stmt = $db->prepare("
                INSERT INTO admin_dashboard_blocks
                    (title, is_active, sort_order)
                VALUES
                    (:title, 1, :sort_order)
            ");
            $stmt->execute([
                'title' => $title,
                'sort_order' => $sortOrder
            ]);

            $id = (int) $db->lastInsertId();
            $db->commit();

            return $id;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function updateBlock($blockId, $title)
    {
        $blockId = self::positiveId($blockId, 'Некоректний блок.');
        $title = self::normalizeTitle($title);
        self::requireBlock($blockId);

        $stmt = Database::connect()->prepare("
            UPDATE admin_dashboard_blocks
            SET title = :title
            WHERE id = :id
        ");
        $stmt->execute([
            'title' => $title,
            'id' => $blockId
        ]);
    }


    public static function toggleBlock($blockId)
    {
        $block = self::requireBlock($blockId);

        $stmt = Database::connect()->prepare("
            UPDATE admin_dashboard_blocks
            SET is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            'is_active' => !empty($block['is_active']) ? 0 : 1,
            'id' => (int) $block['id']
        ]);
    }


    public static function deleteBlock($blockId)
    {
        $blockId = self::positiveId($blockId, 'Некоректний блок.');
        self::requireBlock($blockId);
        $db = Database::connect();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                DELETE FROM admin_dashboard_blocks
                WHERE id = :id
            ");
            $stmt->execute(['id' => $blockId]);
            self::normalizeBlockOrder($db);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function createLink(
        $blockId,
        $serviceKey,
        $labelOverride = ''
    ) {
        self::ensureSchema();
        $blockId = self::positiveId($blockId, 'Некоректний блок.');
        $service = self::requireUsableService($serviceKey);
        $labelOverride = self::normalizeLabelOverride($labelOverride);
        $db = Database::connect();
        $db->beginTransaction();

        try {
            self::lockBlock($db, $blockId);
            self::assertServiceCanBeAdded(
                $db,
                $service,
                0
            );

            $lastStmt = $db->prepare("
                SELECT id, sort_order
                FROM admin_dashboard_links
                WHERE block_id = :block_id
                ORDER BY sort_order DESC, id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $lastStmt->execute(['block_id' => $blockId]);
            $last = $lastStmt->fetch(PDO::FETCH_ASSOC);
            $sortOrder = $last
                ? (int) ($last['sort_order'] ?? 0) + 10
                : 10;

            $stmt = $db->prepare("
                INSERT INTO admin_dashboard_links
                    (
                        block_id,
                        service_key,
                        label_override,
                        is_active,
                        sort_order
                    )
                VALUES
                    (
                        :block_id,
                        :service_key,
                        :label_override,
                        1,
                        :sort_order
                    )
            ");
            $stmt->execute([
                'block_id' => $blockId,
                'service_key' => (string) $service['key'],
                'label_override' => $labelOverride !== ''
                    ? $labelOverride
                    : null,
                'sort_order' => $sortOrder
            ]);

            $id = (int) $db->lastInsertId();
            $db->commit();

            return $id;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function updateLink(
        $linkId,
        $serviceKey,
        $labelOverride = ''
    ) {
        self::ensureSchema();
        $linkId = self::positiveId($linkId, 'Некоректне посилання.');
        $link = self::requireLink($linkId);
        $service = self::requireUsableService($serviceKey);
        $labelOverride = self::normalizeLabelOverride($labelOverride);
        $db = Database::connect();
        $db->beginTransaction();

        try {
            self::lockBlock(
                $db,
                (int) $link['block_id']
            );
            self::assertServiceCanBeAdded(
                $db,
                $service,
                $linkId
            );

            $stmt = $db->prepare("
                UPDATE admin_dashboard_links
                SET
                    service_key = :service_key,
                    label_override = :label_override
                WHERE id = :id
            ");
            $stmt->execute([
                'service_key' => (string) $service['key'],
                'label_override' => $labelOverride !== ''
                    ? $labelOverride
                    : null,
                'id' => $linkId
            ]);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function toggleLink($linkId)
    {
        $link = self::requireLink($linkId);

        $stmt = Database::connect()->prepare("
            UPDATE admin_dashboard_links
            SET is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            'is_active' => !empty($link['is_active']) ? 0 : 1,
            'id' => (int) $link['id']
        ]);
    }


    public static function deleteLink($linkId)
    {
        $link = self::requireLink($linkId);
        $db = Database::connect();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                DELETE FROM admin_dashboard_links
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => (int) $link['id']
            ]);
            self::normalizeLinkOrder(
                $db,
                (int) $link['block_id']
            );
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function reorderBlocks(array $blockIds)
    {
        self::ensureSchema();
        $ids = self::normalizeIdList(
            $blockIds,
            'Некоректний список блоків для сортування.'
        );

        if (empty($ids)) {
            throw new InvalidArgumentException(
                'Список блоків для сортування порожній.'
            );
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $currentIds = array_map(
                'intval',
                $db->query("
                    SELECT id
                    FROM admin_dashboard_blocks
                    ORDER BY sort_order ASC, id ASC
                    FOR UPDATE
                ")->fetchAll(PDO::FETCH_COLUMN)
            );

            self::assertSameIdSet(
                $currentIds,
                $ids,
                'Склад блоків змінився. Оновіть сторінку та повторіть переміщення.'
            );

            if ($currentIds === $ids) {
                $db->commit();
                return false;
            }

            $update = $db->prepare("
                UPDATE admin_dashboard_blocks
                SET sort_order = :sort_order
                WHERE id = :id
            ");

            foreach ($ids as $position => $id) {
                $update->execute([
                    'sort_order' => ($position + 1) * 10,
                    'id' => (int) $id
                ]);
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


    public static function reorderLinks(array $layout)
    {
        self::ensureSchema();
        $submitted = self::normalizeLinkLayout($layout);
        $db = Database::connect();
        $db->beginTransaction();

        try {
            $currentBlockIds = array_map(
                'intval',
                $db->query("
                    SELECT id
                    FROM admin_dashboard_blocks
                    ORDER BY id ASC
                    FOR UPDATE
                ")->fetchAll(PDO::FETCH_COLUMN)
            );

            self::assertSameIdSet(
                $currentBlockIds,
                array_keys($submitted),
                'Склад блоків змінився. Оновіть сторінку та повторіть переміщення.'
            );

            $rows = $db->query("
                SELECT id, block_id, service_key, sort_order
                FROM admin_dashboard_links
                ORDER BY id ASC
                FOR UPDATE
            ")->fetchAll(PDO::FETCH_ASSOC);

            $currentById = [];
            $currentLinkIds = [];

            foreach ($rows as $row) {
                $linkId = (int) ($row['id'] ?? 0);

                if ($linkId <= 0) {
                    continue;
                }

                $currentById[$linkId] = [
                    'block_id' => (int) ($row['block_id'] ?? 0),
                    'service_key' => (string) ($row['service_key'] ?? ''),
                    'sort_order' => (int) ($row['sort_order'] ?? 0)
                ];
                $currentLinkIds[] = $linkId;
            }

            $submittedLinkIds = [];

            foreach ($submitted as $blockId => $linkIds) {
                foreach ($linkIds as $linkId) {
                    $submittedLinkIds[] = (int) $linkId;
                }
            }

            self::assertSameIdSet(
                $currentLinkIds,
                $submittedLinkIds,
                'Склад ярликів змінився. Оновіть сторінку та повторіть переміщення.'
            );

            foreach ($submitted as $blockId => $linkIds) {
                $serviceKeys = [];

                foreach ($linkIds as $linkId) {
                    $serviceKey = (string) (
                        $currentById[(int) $linkId]['service_key']
                        ?? ''
                    );

                    if ($serviceKey === '') {
                        throw new InvalidArgumentException(
                            'Службу ярлика не знайдено.'
                        );
                    }

                    if (isset($serviceKeys[$serviceKey])) {
                        throw new DomainException(
                            'В одному блоці не можна розмістити дві однакові служби.'
                        );
                    }

                    $serviceKeys[$serviceKey] = true;
                }
            }

            $changed = false;
            $update = $db->prepare("
                UPDATE admin_dashboard_links
                SET
                    block_id = :block_id,
                    sort_order = :sort_order
                WHERE id = :id
            ");

            foreach ($submitted as $blockId => $linkIds) {
                foreach ($linkIds as $position => $linkId) {
                    $linkId = (int) $linkId;
                    $sortOrder = ($position + 1) * 10;
                    $current = $currentById[$linkId] ?? null;

                    if (
                        !is_array($current)
                        || (int) $current['block_id'] !== (int) $blockId
                        || (int) $current['sort_order'] !== $sortOrder
                    ) {
                        $changed = true;
                    }

                    $update->execute([
                        'block_id' => (int) $blockId,
                        'sort_order' => $sortOrder,
                        'id' => $linkId
                    ]);
                }
            }

            $db->commit();
            return $changed;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function usedServiceKeys()
    {
        self::ensureSchema();

        return array_values(array_unique(array_map(
            'strval',
            Database::connect()->query("
                SELECT service_key
                FROM admin_dashboard_links
                ORDER BY id ASC
            ")->fetchAll(PDO::FETCH_COLUMN)
        )));
    }


    private static function requireBlock($blockId)
    {
        self::ensureSchema();
        $blockId = self::positiveId($blockId, 'Некоректний блок.');
        $stmt = Database::connect()->prepare("
            SELECT id, title, is_active, sort_order
            FROM admin_dashboard_blocks
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $blockId]);
        $block = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$block) {
            throw new InvalidArgumentException(
                'Блок головної адмін-панелі не знайдено.'
            );
        }

        return $block;
    }


    private static function requireLink($linkId)
    {
        self::ensureSchema();
        $linkId = self::positiveId($linkId, 'Некоректне посилання.');
        $stmt = Database::connect()->prepare("
            SELECT
                id,
                block_id,
                service_key,
                label_override,
                is_active,
                sort_order
            FROM admin_dashboard_links
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $linkId]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$link) {
            throw new InvalidArgumentException(
                'Посилання головної адмін-панелі не знайдено.'
            );
        }

        return $link;
    }


    private static function requireUsableService($serviceKey)
    {
        if (!class_exists('AdminDashboardServiceRegistry')) {
            throw new RuntimeException(
                'Реєстр служб адмін-панелі недоступний.'
            );
        }

        $service = AdminDashboardServiceRegistry::requireService(
            $serviceKey
        );

        if (!AdminDashboardServiceRegistry::canUse($service['key'])) {
            throw new DomainException(
                'Ця служба недоступна вашій ролі.'
            );
        }

        return $service;
    }


    private static function assertServiceCanBeAdded(
        PDO $db,
        array $service,
        $excludeLinkId
    ) {
        if (!empty($service['allow_multiple'])) {
            return;
        }

        $stmt = $db->prepare("
            SELECT id
            FROM admin_dashboard_links
            WHERE service_key = :service_key
              AND id <> :exclude_id
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([
            'service_key' => (string) $service['key'],
            'exclude_id' => max(0, (int) $excludeLinkId)
        ]);

        if ($stmt->fetchColumn()) {
            throw new DomainException(
                'Цю службу вже додано на головну адмін-панель.'
            );
        }
    }


    private static function lockBlock(PDO $db, $blockId)
    {
        $stmt = $db->prepare("
            SELECT id
            FROM admin_dashboard_blocks
            WHERE id = :id
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([
            'id' => (int) $blockId
        ]);

        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException(
                'Блок головної адмін-панелі не знайдено.'
            );
        }
    }


    private static function normalizeBlockOrder(PDO $db)
    {
        $ids = array_map(
            'intval',
            $db->query("
                SELECT id
                FROM admin_dashboard_blocks
                ORDER BY sort_order ASC, id ASC
                FOR UPDATE
            ")->fetchAll(PDO::FETCH_COLUMN)
        );
        $stmt = $db->prepare("
            UPDATE admin_dashboard_blocks
            SET sort_order = :sort_order
            WHERE id = :id
        ");

        foreach ($ids as $index => $id) {
            $stmt->execute([
                'sort_order' => ($index + 1) * 10,
                'id' => $id
            ]);
        }
    }


    private static function normalizeLinkOrder(PDO $db, $blockId)
    {
        $stmt = $db->prepare("
            SELECT id
            FROM admin_dashboard_links
            WHERE block_id = :block_id
            ORDER BY sort_order ASC, id ASC
            FOR UPDATE
        ");
        $stmt->execute([
            'block_id' => (int) $blockId
        ]);
        $ids = array_map(
            'intval',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );
        $update = $db->prepare("
            UPDATE admin_dashboard_links
            SET sort_order = :sort_order
            WHERE id = :id
        ");

        foreach ($ids as $index => $id) {
            $update->execute([
                'sort_order' => ($index + 1) * 10,
                'id' => $id
            ]);
        }
    }


    private static function normalizeIdList(
        array $values,
        $message
    ) {
        $ids = [];

        foreach ($values as $value) {
            $raw = trim((string) $value);

            if ($raw === '' || !preg_match('/^\\d+$/', $raw)) {
                throw new InvalidArgumentException(
                    (string) $message
                );
            }

            $id = (int) $raw;

            if ($id <= 0) {
                throw new InvalidArgumentException(
                    (string) $message
                );
            }

            $ids[] = $id;
        }

        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException(
                (string) $message
            );
        }

        return $ids;
    }


    private static function normalizeLinkLayout(array $layout)
    {
        if (empty($layout)) {
            throw new InvalidArgumentException(
                'Розкладка ярликів порожня.'
            );
        }

        $result = [];
        $allLinkIds = [];

        foreach ($layout as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException(
                    'Некоректна розкладка ярликів.'
                );
            }

            $blockId = self::positiveId(
                $row['block_id'] ?? 0,
                'Некоректний блок у розкладці ярликів.'
            );

            if (array_key_exists($blockId, $result)) {
                throw new InvalidArgumentException(
                    'Блок повторюється у розкладці ярликів.'
                );
            }

            $linkIds = self::normalizeIdList(
                is_array($row['link_ids'] ?? null)
                    ? $row['link_ids']
                    : [],
                'Некоректний список ярликів.'
            );

            foreach ($linkIds as $linkId) {
                if (isset($allLinkIds[$linkId])) {
                    throw new InvalidArgumentException(
                        'Ярлик повторюється у розкладці.'
                    );
                }

                $allLinkIds[$linkId] = true;
            }

            $result[$blockId] = $linkIds;
        }

        return $result;
    }


    private static function assertSameIdSet(
        array $expected,
        array $submitted,
        $message
    ) {
        $expected = array_map('intval', $expected);
        $submitted = array_map('intval', $submitted);
        sort($expected, SORT_NUMERIC);
        sort($submitted, SORT_NUMERIC);

        if ($expected !== $submitted) {
            throw new InvalidArgumentException(
                (string) $message
            );
        }
    }


    private static function normalizeTitle($title)
    {
        $title = trim((string) $title);

        if ($title === '') {
            throw new InvalidArgumentException(
                'Вкажіть назву блоку.'
            );
        }

        if (self::length($title) > 120) {
            throw new InvalidArgumentException(
                'Назва блоку має містити не більше 120 символів.'
            );
        }

        return $title;
    }


    private static function normalizeLabelOverride($label)
    {
        $label = trim((string) $label);

        if (self::length($label) > 120) {
            throw new InvalidArgumentException(
                'Назва посилання має містити не більше 120 символів.'
            );
        }

        return $label;
    }


    private static function positiveId($value, $message)
    {
        $id = (int) $value;

        if ($id <= 0) {
            throw new InvalidArgumentException((string) $message);
        }

        return $id;
    }


    private static function length($value)
    {
        return function_exists('mb_strlen')
            ? mb_strlen((string) $value, 'UTF-8')
            : strlen((string) $value);
    }
}
