<?php

class DeliveryRequirements
{
    /**
     * Определить обязательные поля доставки
     * по исходному способу доставки.
     *
     * Важно: ориентируемся не на slug, потому что
     * он создаётся автоматически и может отличаться
     * после переименования способа доставки.
     */
    public static function forMethod(
        array $method,
        array $services = []
    ) {
        $name = mb_strtolower(
            trim((string) ($method['name'] ?? '')),
            'UTF-8'
        );

        $name = str_replace(
            ["'", '’', '`', 'ʼ'],
            '',
            $name
        );

        $isPickup =
            strpos($name, 'самов') !== false
            || strpos($name, 'pickup') !== false;

        if ($isPickup) {
            return [
                'country' => false,
                'city' => false,
                'address' => false,
                'postcode' => false
            ];
        }

        $isCourier =
            strpos($name, 'кур') !== false
            || strpos($name, 'courier') !== false;

        $isPostal =
            strpos($name, 'пошт') !== false
            || strpos($name, 'почт') !== false
            || strpos($name, 'post') !== false
            || !empty($services);

        if ($isCourier || $isPostal) {
            return [
                'country' => true,
                'city' => true,
                'address' => true,
                'postcode' => false
            ];
        }

        return [
            'country' => false,
            'city' => false,
            'address' => false,
            'postcode' => false
        ];
    }
}
