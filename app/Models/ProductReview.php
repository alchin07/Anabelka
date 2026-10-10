<?php

class ProductReview
{
    public static function latestApprovedStandard($limit)
    {
        $limit = max(1, min(100, (int) $limit));
        $visibleIds = Category::visibleCategoryIds();
        $adultIds = Category::adultCategoryIds();
        $allowedIds = array_values(array_diff($visibleIds, $adultIds));

        if (empty($allowedIds)) {
            return [];
        }

        $categoryList = implode(',', array_map('intval', $allowedIds));

        return Database::connect()->query("
            SELECT
                pr.id,
                pr.product_id,
                pr.user_id,
                pr.rating,
                pr.body,
                pr.status,
                pr.created_at,
                p.name AS product_name,
                p.slug AS product_slug,
                p.category_id,
                u.name AS customer_name
            FROM product_reviews pr
            INNER JOIN products p ON p.id = pr.product_id
            INNER JOIN users u ON u.id = pr.user_id
            WHERE pr.status = 'approved'
              AND p.is_active = 1
              AND u.is_active = 1
              AND p.category_id IN ({$categoryList})
            ORDER BY pr.created_at DESC, pr.id DESC
            LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function approvedForProduct($productId)
    {
        $stmt = Database::connect()->prepare("
            SELECT
                pr.id,
                pr.product_id,
                pr.user_id,
                pr.rating,
                pr.body,
                pr.status,
                pr.created_at,
                u.name AS customer_name
            FROM product_reviews pr
            INNER JOIN users u ON u.id = pr.user_id
            WHERE pr.product_id = :product_id
              AND pr.status = 'approved'
              AND u.is_active = 1
            ORDER BY pr.created_at DESC, pr.id DESC
        ");
        $stmt->execute(['product_id' => (int) $productId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function hasReview($productId, $userId)
    {
        $stmt = Database::connect()->prepare("
            SELECT 1
            FROM product_reviews
            WHERE product_id = :product_id
              AND user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([
            'product_id' => (int) $productId,
            'user_id' => (int) $userId
        ]);

        return (bool) $stmt->fetchColumn();
    }


    public static function submit($productId, $userId, $rating, $body)
    {
        $productId = (int) $productId;
        $userId = (int) $userId;
        $rating = (int) $rating;
        $body = trim((string) $body);
        $bodyLength = function_exists('mb_strlen')
            ? mb_strlen($body, 'UTF-8')
            : strlen($body);

        if ($productId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('Некоректний товар або користувач.');
        }

        if ($rating < 1 || $rating > 5) {
            throw new InvalidArgumentException('Оцінка має бути від 1 до 5.');
        }

        if ($bodyLength < 3 || $bodyLength > 1500) {
            throw new InvalidArgumentException(
                'Відгук має містити від 3 до 1500 символів.'
            );
        }

        if (self::hasReview($productId, $userId)) {
            throw new DomainException('Ви вже залишили відгук про цей товар.');
        }

        $stmt = Database::connect()->prepare("
            INSERT INTO product_reviews
            (
                product_id,
                user_id,
                rating,
                body,
                status
            )
            VALUES
            (
                :product_id,
                :user_id,
                :rating,
                :body,
                'pending'
            )
        ");

        try {
            $stmt->execute([
                'product_id' => $productId,
                'user_id' => $userId,
                'rating' => $rating,
                'body' => $body
            ]);
        } catch (PDOException $e) {
            if ((string) ($e->errorInfo[1] ?? '') === '1062') {
                throw new DomainException(
                    'Ви вже залишили відгук про цей товар.'
                );
            }
            throw $e;
        }

        return (int) Database::connect()->lastInsertId();
    }


    public static function adminList($status = '')
    {
        $status = trim((string) $status);
        $allowedStatuses = ['pending', 'approved', 'rejected'];
        $where = '';
        $params = [];

        if (in_array($status, $allowedStatuses, true)) {
            $where = 'WHERE pr.status = :status';
            $params['status'] = $status;
        }

        $stmt = Database::connect()->prepare("
            SELECT
                pr.id,
                pr.product_id,
                pr.user_id,
                pr.rating,
                pr.body,
                pr.status,
                pr.moderated_by_admin_id,
                pr.moderated_at,
                pr.created_at,
                p.name AS product_name,
                p.slug AS product_slug,
                u.name AS customer_name
            FROM product_reviews pr
            INNER JOIN products p ON p.id = pr.product_id
            INNER JOIN users u ON u.id = pr.user_id
            {$where}
            ORDER BY
                CASE pr.status
                    WHEN 'pending' THEN 0
                    WHEN 'approved' THEN 1
                    ELSE 2
                END,
                pr.created_at DESC,
                pr.id DESC
            LIMIT 300
        ");
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function moderate($reviewId, $status, $adminId)
    {
        $reviewId = (int) $reviewId;
        $adminId = (int) $adminId;
        $status = trim((string) $status);

        if ($reviewId <= 0 || $adminId <= 0) {
            throw new InvalidArgumentException('Некоректний відгук або адміністратор.');
        }

        if (!in_array($status, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Некоректний статус модерації.');
        }

        $stmt = Database::connect()->prepare("
            UPDATE product_reviews
            SET status = :status,
                moderated_by_admin_id = :admin_id,
                moderated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            'status' => $status,
            'admin_id' => $adminId,
            'id' => $reviewId
        ]);

        if ($stmt->rowCount() < 1) {
            throw new InvalidArgumentException('Відгук не знайдено.');
        }
    }


    public static function delete($reviewId)
    {
        $reviewId = (int) $reviewId;

        if ($reviewId <= 0) {
            throw new InvalidArgumentException('Некоректний відгук.');
        }

        $stmt = Database::connect()->prepare("
            DELETE FROM product_reviews
            WHERE id = :id
        ");
        $stmt->execute(['id' => $reviewId]);
    }
}
