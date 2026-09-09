<?php

class CustomerNotificationInterfaceTranslator
{
    private static $seeded = false;


    public static function seed()
    {
        if (self::$seeded) {
            return;
        }

        Translator::currentLanguage();
        $db = Database::connect();
        $translations = [
            'uk' => [
                'public.notifications.title' => 'Сповіщення',
                'public.notifications.unread' => 'Нових',
                'public.notifications.empty' => 'Нових сповіщень поки немає.',
                'public.notifications.rank_approved_title' => 'Ранг підвищено',
                'public.notifications.rank_approved_text' => 'Ваш запит схвалено. Новий ранг: {rank}.',
                'public.notifications.rank_rejected_title' => 'Запит на підвищення відхилено',
                'public.notifications.rank_rejected_text' => 'Адміністратор відхилив запит на підвищення рангу.',
                'public.notifications.admin_note' => 'Примітка адміністратора'
            ],
            'ru' => [
                'public.notifications.title' => 'Уведомления',
                'public.notifications.unread' => 'Новых',
                'public.notifications.empty' => 'Новых уведомлений пока нет.',
                'public.notifications.rank_approved_title' => 'Ранг повышен',
                'public.notifications.rank_approved_text' => 'Ваш запрос одобрен. Новый ранг: {rank}.',
                'public.notifications.rank_rejected_title' => 'Запрос на повышение отклонён',
                'public.notifications.rank_rejected_text' => 'Администратор отклонил запрос на повышение ранга.',
                'public.notifications.admin_note' => 'Примечание администратора'
            ],
            'en' => [
                'public.notifications.title' => 'Notifications',
                'public.notifications.unread' => 'New',
                'public.notifications.empty' => 'There are no new notifications yet.',
                'public.notifications.rank_approved_title' => 'Rank upgraded',
                'public.notifications.rank_approved_text' => 'Your request was approved. New rank: {rank}.',
                'public.notifications.rank_rejected_title' => 'Rank upgrade request rejected',
                'public.notifications.rank_rejected_text' => 'The administrator rejected your rank upgrade request.',
                'public.notifications.admin_note' => 'Administrator note'
            ]
        ];

        $stmt = $db->prepare("
            INSERT IGNORE INTO interface_translations
            (translation_key, language_code, value, source, status)
            VALUES
            (:translation_key, :language_code, :value, 'manual', 'approved')
        ");

        foreach ($translations as $languageCode => $items) {
            foreach ($items as $key => $value) {
                $stmt->execute([
                    'translation_key' => $key,
                    'language_code' => $languageCode,
                    'value' => $value
                ]);
            }
        }

        self::$seeded = true;
    }
}
