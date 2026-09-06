<?php

class CartColorMigration
{
    public static function prepareOrderItems(array &$items)
    {
        $allResolved = true;
        $userId = !empty($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : 0;

        foreach ($items as &$item) {
            $product = is_array($item['product'] ?? null)
                ? $item['product']
                : null;
            $productId = (int) ($product['id'] ?? 0);
            $sizeId = (int) ($item['size_id'] ?? 0);
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $colorKey = trim((string) ($item['color_key'] ?? ''));

            if (
                $productId <= 0
                || $sizeId <= 0
                || !ProductVariantStock::hasMatrix($productId)
            ) {
                continue;
            }

            if ($colorKey !== '') {
                $color = ProductVariantStock::colorInfo(
                    $productId,
                    $colorKey
                );

                if ($color) {
                    $item['color_name'] = (string) $color['color_name'];
                    $item['color_hex'] = (string) ($color['color_hex'] ?? '');
                    continue;
                }
            }

            $options = self::availableOptions(
                $productId,
                $sizeId,
                $quantity,
                $userId
            );

            if (count($options) !== 1) {
                $allResolved = false;
                continue;
            }

            $color = $options[0];
            $item['color_key'] = (string) $color['color_key'];
            $item['color_name'] = (string) $color['color_name'];
            $item['color_hex'] = (string) ($color['color_hex'] ?? '');

            if ($userId > 0) {
                try {
                    self::assignUserVariantColor(
                        $userId,
                        $productId,
                        $sizeId,
                        (string) $color['color_key']
                    );
                } catch (Throwable $e) {
                    // Поточне замовлення ще раз перевірить залишок нижче.
                    $allResolved = false;
                }
            }
        }
        unset($item);

        return $allResolved;
    }


    public static function describeUserCartItem($userId, $cartKey)
    {
        $row = self::findUserItemByKey($userId, $cartKey);

        if (!$row) {
            return [
                'requires_color' => false,
                'options' => []
            ];
        }

        $productId = (int) $row['product_id'];
        $sizeId = (int) $row['size_id'];
        $quantity = max(1, (int) $row['quantity']);
        $colorKey = trim((string) ($row['color_key'] ?? ''));

        if (
            $colorKey !== ''
            || !ProductVariantStock::hasMatrix($productId)
        ) {
            return [
                'requires_color' => false,
                'options' => []
            ];
        }

        return [
            'requires_color' => true,
            'product_id' => $productId,
            'size_id' => $sizeId,
            'quantity' => $quantity,
            'options' => self::allOptions(
                $productId,
                $sizeId,
                $quantity,
                (int) $userId
            )
        ];
    }


    public static function assignUserVariantColor(
        $userId,
        $productId,
        $sizeId,
        $colorKey
    ) {
        Cart::ensureColorSupport();
        $userId = (int) $userId;
        $productId = (int) $productId;
        $sizeId = (int) $sizeId;
        $colorKey = trim((string) $colorKey);

        if (
            $userId <= 0
            || $productId <= 0
            || $sizeId <= 0
            || $colorKey === ''
        ) {
            throw new InvalidArgumentException('Некоректні дані кольору.');
        }

        $cart = Cart::getOrCreateByUserId($userId);

        if (!$cart) {
            throw new RuntimeException('Кошик не знайдено.');
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $legacyStmt = $db->prepare("
                SELECT *
                FROM cart_items
                WHERE cart_id = :cart_id
                  AND product_id = :product_id
                  AND size_id = :size_id
                  AND color_key = ''
                LIMIT 1
                FOR UPDATE
            ");
            $legacyStmt->execute([
                'cart_id' => (int) $cart['id'],
                'product_id' => $productId,
                'size_id' => $sizeId
            ]);
            $legacy = $legacyStmt->fetch(PDO::FETCH_ASSOC);

            if (!$legacy) {
                $db->commit();
                return true;
            }

            $quantity = max(1, (int) $legacy['quantity']);
            $options = self::allOptions(
                $productId,
                $sizeId,
                $quantity,
                $userId
            );
            $selected = null;

            foreach ($options as $option) {
                if ((string) $option['color_key'] === $colorKey) {
                    $selected = $option;
                    break;
                }
            }

            if (!$selected) {
                throw new RuntimeException(
                    'Цей колір недоступний для вибраного розміру.'
                );
            }

            if (empty($selected['available'])) {
                throw new RuntimeException(
                    'Недостатньо товару цього кольору та розміру.'
                );
            }

            $existingStmt = $db->prepare("
                SELECT id, quantity
                FROM cart_items
                WHERE cart_id = :cart_id
                  AND product_id = :product_id
                  AND size_id = :size_id
                  AND color_key = :color_key
                  AND id <> :legacy_id
                LIMIT 1
                FOR UPDATE
            ");
            $existingStmt->execute([
                'cart_id' => (int) $cart['id'],
                'product_id' => $productId,
                'size_id' => $sizeId,
                'color_key' => $colorKey,
                'legacy_id' => (int) $legacy['id']
            ]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            $existingQuantity = $existing
                ? max(0, (int) $existing['quantity'])
                : 0;
            $stock = ProductVariantStock::stockFor(
                $productId,
                $sizeId,
                $colorKey
            );

            if ($existingQuantity + $quantity > $stock) {
                throw new RuntimeException(
                    'Недостатньо товару цього кольору та розміру.'
                );
            }

            if ($existing) {
                $db->prepare("
                    UPDATE cart_items
                    SET quantity = :quantity,
                        color_name = :color_name,
                        color_hex = :color_hex
                    WHERE id = :id
                ")->execute([
                    'quantity' => $existingQuantity + $quantity,
                    'color_name' => (string) $selected['color_name'],
                    'color_hex' => self::nullableHex($selected['color_hex'] ?? ''),
                    'id' => (int) $existing['id']
                ]);

                $db->prepare("
                    DELETE FROM cart_items
                    WHERE id = :id
                ")->execute(['id' => (int) $legacy['id']]);
            } else {
                $db->prepare("
                    UPDATE cart_items
                    SET color_key = :color_key,
                        color_name = :color_name,
                        color_hex = :color_hex
                    WHERE id = :id
                ")->execute([
                    'color_key' => $colorKey,
                    'color_name' => (string) $selected['color_name'],
                    'color_hex' => self::nullableHex($selected['color_hex'] ?? ''),
                    'id' => (int) $legacy['id']
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


    private static function availableOptions(
        $productId,
        $sizeId,
        $quantity,
        $userId = 0
    ) {
        return array_values(array_filter(
            self::allOptions(
                $productId,
                $sizeId,
                $quantity,
                $userId
            ),
            function ($option) {
                return !empty($option['available']);
            }
        ));
    }


    private static function allOptions(
        $productId,
        $sizeId,
        $quantity,
        $userId = 0
    ) {
        $productId = (int) $productId;
        $sizeId = (int) $sizeId;
        $quantity = max(1, (int) $quantity);
        $userId = (int) $userId;
        $cartQuantities = [];

        if ($userId > 0) {
            $cart = Cart::getOrCreateByUserId($userId);

            if ($cart) {
                $db = Database::connect();
                $stmt = $db->prepare("
                    SELECT
                        color_key,
                        COALESCE(SUM(quantity), 0) AS quantity
                    FROM cart_items
                    WHERE cart_id = :cart_id
                      AND product_id = :product_id
                      AND size_id = :size_id
                      AND color_key <> ''
                    GROUP BY color_key
                ");
                $stmt->execute([
                    'cart_id' => (int) $cart['id'],
                    'product_id' => $productId,
                    'size_id' => $sizeId
                ]);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $cartQuantities[(string) $row['color_key']] =
                        max(0, (int) $row['quantity']);
                }
            }
        }

        $options = [];
        $seen = [];

        foreach (ProductVariantStock::forProduct($productId) as $row) {
            if ((int) ($row['size_value_id'] ?? 0) !== $sizeId) {
                continue;
            }

            $key = trim((string) ($row['color_key'] ?? ''));

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $stock = max(0, (int) ($row['stock'] ?? 0));
            $alreadyInCart = max(0, (int) ($cartQuantities[$key] ?? 0));
            $availableQuantity = max(0, $stock - $alreadyInCart);

            $options[] = [
                'color_key' => $key,
                'color_name' => (string) ($row['color_name'] ?? ''),
                'color_hex' => (string) ($row['color_hex'] ?? ''),
                'stock' => $stock,
                'cart_quantity' => $alreadyInCart,
                'available_quantity' => $availableQuantity,
                'available' => $availableQuantity >= $quantity
            ];
        }

        return $options;
    }


    private static function findUserItemByKey($userId, $cartKey)
    {
        Cart::ensureColorSupport();
        $userId = (int) $userId;
        [$productId, $sizeId, $colorKey] = self::parseCartKey($cartKey);

        if ($userId <= 0 || $productId <= 0 || $sizeId <= 0) {
            return null;
        }

        $cart = Cart::getOrCreateByUserId($userId);

        if (!$cart) {
            return null;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT *
            FROM cart_items
            WHERE cart_id = :cart_id
              AND product_id = :product_id
              AND size_id = :size_id
              AND color_key = :color_key
            LIMIT 1
        ");
        $stmt->execute([
            'cart_id' => (int) $cart['id'],
            'product_id' => $productId,
            'size_id' => $sizeId,
            'color_key' => $colorKey
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }


    private static function parseCartKey($cartKey)
    {
        $parts = explode('_', (string) $cartKey, 3);
        $colorKey = '';

        if (!empty($parts[2])) {
            $encoded = strtr((string) $parts[2], '-_', '+/');
            $padding = strlen($encoded) % 4;

            if ($padding > 0) {
                $encoded .= str_repeat('=', 4 - $padding);
            }

            $decoded = base64_decode($encoded, true);

            if ($decoded !== false) {
                $colorKey = (string) $decoded;
            }
        }

        return [
            (int) ($parts[0] ?? 0),
            (int) ($parts[1] ?? 0),
            $colorKey
        ];
    }


    private static function nullableHex($value)
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^#[0-9a-f]{6}$/', $value)
            ? $value
            : null;
    }
}
