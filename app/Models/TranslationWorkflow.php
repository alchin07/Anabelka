<?php

class TranslationWorkflow
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEW = 'review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_OUTDATED = 'outdated';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AI = 'ai';


    public static function normalizeStatus(
        $status,
        $hasContent = true,
        $default = self::STATUS_APPROVED
    ) {
        if (!$hasContent) {
            return self::STATUS_DRAFT;
        }

        $status = strtolower(trim((string) $status));
        $allowed = [
            self::STATUS_DRAFT,
            self::STATUS_REVIEW,
            self::STATUS_APPROVED,
            self::STATUS_OUTDATED
        ];

        return in_array($status, $allowed, true)
            ? $status
            : $default;
    }


    public static function normalizeSource($source)
    {
        return strtolower(trim((string) $source)) === self::SOURCE_AI
            ? self::SOURCE_AI
            : self::SOURCE_MANUAL;
    }


    public static function sourceChanged(
        $oldName,
        $oldDescription,
        $newName,
        $newDescription
    ) {
        return trim((string) $oldName) !== trim((string) $newName)
            || trim((string) $oldDescription)
                !== trim((string) $newDescription);
    }


    public static function translationChanged(
        array $translation,
        $newName,
        $newDescription
    ) {
        return trim((string) ($translation['name'] ?? ''))
                !== trim((string) $newName)
            || trim((string) ($translation['description'] ?? ''))
                !== trim((string) $newDescription);
    }


    public static function stateCode(
        $status,
        $source,
        $hasContent = true
    ) {
        if (!$hasContent) {
            return 'missing';
        }

        $status = self::normalizeStatus(
            $status,
            true,
            self::STATUS_DRAFT
        );

        if ($status === self::STATUS_DRAFT) {
            return self::normalizeSource($source) === self::SOURCE_AI
                ? 'ai_draft'
                : 'manual_draft';
        }

        return $status;
    }


    public static function stateLabel($state)
    {
        $labels = [
            'missing' => 'Відсутній',
            'ai_draft' => 'Чернетка ШІ',
            'manual_draft' => 'Ручна чернетка',
            self::STATUS_REVIEW => 'Очікує перевірки',
            self::STATUS_APPROVED => 'Схвалено',
            self::STATUS_OUTDATED => 'Потрібне оновлення'
        ];

        return $labels[(string) $state] ?? 'Чернетка';
    }


    public static function statusOptions()
    {
        return [
            self::STATUS_DRAFT => 'Чернетка',
            self::STATUS_REVIEW => 'Очікує перевірки',
            self::STATUS_APPROVED => 'Схвалено',
            self::STATUS_OUTDATED => 'Потрібне оновлення'
        ];
    }


    public static function sourceLabel($source)
    {
        return self::normalizeSource($source) === self::SOURCE_AI
            ? 'Створено ШІ'
            : 'Ручний переклад';
    }
}
