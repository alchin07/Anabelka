<?php

class StorefrontProductCollection
{
    private const PER_PAGE = 24;


    public static function pageMeta($pageInput, $total)
    {
        $total = max(0, (int) $total);
        $totalPages = max(
            1,
            (int) ceil($total / self::PER_PAGE)
        );
        $page = self::normalizePage($pageInput);
        $page = min($page, $totalPages);

        return [
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages
        ];
    }


    public static function discountPercent(
        $oldPrice,
        $currentPrice,
        $activePercent
    ) {
        $activePercent = self::finiteNumber($activePercent);

        if (
            $activePercent !== null
            && $activePercent > 0
            && $activePercent <= 100
        ) {
            return round($activePercent, 2);
        }

        $oldPrice = self::finiteNumber($oldPrice);
        $currentPrice = self::finiteNumber($currentPrice);

        if (
            $oldPrice === null
            || $currentPrice === null
            || $oldPrice <= 0
            || $currentPrice < 0
            || $oldPrice <= $currentPrice
        ) {
            return null;
        }

        return round(
            (($oldPrice - $currentPrice) / $oldPrice) * 100,
            2
        );
    }


    private static function normalizePage($pageInput)
    {
        if (is_int($pageInput)) {
            return $pageInput > 0 ? $pageInput : 1;
        }

        if (!is_string($pageInput)) {
            return 1;
        }

        $pageInput = trim($pageInput);

        if (!preg_match('/^[1-9][0-9]*$/', $pageInput)) {
            return 1;
        }

        $validated = filter_var(
            $pageInput,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => PHP_INT_MAX
                ]
            ]
        );

        return $validated === false ? 1 : (int) $validated;
    }


    private static function finiteNumber($value)
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }
}
