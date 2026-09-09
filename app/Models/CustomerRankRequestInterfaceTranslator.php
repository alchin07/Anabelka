<?php

class CustomerRankRequestInterfaceTranslator
{
    private static $seeded = false;


    public static function seed()
    {
        if (self::$seeded) {
            return;
        }

        Translator::currentLanguage();
        $translations = [
            'uk' => [
                'public.rank_request.title' => 'Підвищення рангу',
                'public.rank_request.hint' => 'Надішліть запит адміністратору. Для розгляду мають бути заповнені телефон і хоча б одна адреса доставки.',
                'public.rank_request.send' => 'Надіслати запит на підвищення',
                'public.rank_request.pending' => 'Запит очікує на розгляд',
                'public.rank_request.approved' => 'Запит схвалено',
                'public.rank_request.rejected' => 'Запит відхилено',
                'public.rank_request.phone_missing' => 'Додайте номер телефону',
                'public.rank_request.address_missing' => 'Додайте адресу доставки',
                'public.rank_request.highest' => 'Ви вже маєте найвищий доступний ранг',
                'public.rank_request.sent' => 'Запит на підвищення рангу надіслано адміністратору.',
                'public.rank_request.current' => 'Поточний ранг',
                'public.rank_request.new_rank' => 'Новий ранг'
            ],
            'ru' => [
                'public.rank_request.title' => 'Повышение ранга',
                'public.rank_request.hint' => 'Отправьте запрос администратору. Для рассмотрения должны быть заполнены телефон и хотя бы один адрес доставки.',
                'public.rank_request.send' => 'Отправить запрос на повышение',
                'public.rank_request.pending' => 'Запрос ожидает рассмотрения',
                'public.rank_request.approved' => 'Запрос одобрен',
                'public.rank_request.rejected' => 'Запрос отклонён',
                'public.rank_request.phone_missing' => 'Добавьте номер телефона',
                'public.rank_request.address_missing' => 'Добавьте адрес доставки',
                'public.rank_request.highest' => 'У вас уже самый высокий доступный ранг',
                'public.rank_request.sent' => 'Запрос на повышение ранга отправлен администратору.',
                'public.rank_request.current' => 'Текущий ранг',
                'public.rank_request.new_rank' => 'Новый ранг'
            ],
            'en' => [
                'public.rank_request.title' => 'Rank upgrade',
                'public.rank_request.hint' => 'Send a request to an administrator. A phone number and at least one delivery address are required for review.',
                'public.rank_request.send' => 'Request a rank upgrade',
                'public.rank_request.pending' => 'Request is awaiting review',
                'public.rank_request.approved' => 'Request approved',
                'public.rank_request.rejected' => 'Request rejected',
                'public.rank_request.phone_missing' => 'Add a phone number',
                'public.rank_request.address_missing' => 'Add a delivery address',
                'public.rank_request.highest' => 'You already have the highest available rank',
                'public.rank_request.sent' => 'Your rank upgrade request was sent to an administrator.',
                'public.rank_request.current' => 'Current rank',
                'public.rank_request.new_rank' => 'New rank'
            ]
        ];

        $stmt = Database::connect()->prepare("
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
