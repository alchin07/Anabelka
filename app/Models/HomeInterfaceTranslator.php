<?php

class HomeInterfaceTranslator
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
                'home.hero_eyebrow' => 'Анабелька',
                'home.hero_title' => 'Для щоденного комфорту, дому, відпочинку та особливих моментів',
                'home.hero_text' => 'Білизна, панчішно-шкарпеткові вироби, домашній одяг, купальники та інші напрямки в одному магазині.',
                'home.hero_catalog' => 'Перейти до каталогу',
                'home.nav_catalog' => 'Каталог',
                'home.directions_title' => 'Напрямки магазину',
                'home.directions_text' => 'Оберіть потрібний розділ і переходьте до категорій та товарів.',
                'home.direction_open' => 'Відкрити розділ',
                'home.latest_title' => 'Новинки',
                'home.latest_text' => 'Останні товари, додані до каталогу.',
                'home.product_open' => 'Переглянути товар',
                'home.all_catalog' => 'Увесь каталог',
                'home.empty_directions' => 'Напрямки магазину ще не налаштовані.',
                'home.empty_products' => 'Нових товарів поки немає.',
                'home.adult_entry_title' => 'Інтимні товари',
                'home.adult_entry_text' => 'Окремий приватний розділ для повнолітніх. Товари 18+ не змішуються зі звичайними рекомендаціями магазину.',
                'home.adult_open' => 'Перейти до розділу 18+',
                'home.adult_gate_title' => 'Підтвердження віку',
                'home.adult_gate_heading' => 'Цей розділ призначений лише для повнолітніх',
                'home.adult_gate_text' => 'Підтвердьте, що вам виповнилося 18 років, щоб перейти до розділу.',
                'home.adult_confirm' => 'Мені вже є 18 років',
                'home.adult_leave' => 'Повернутися на головну',
                'home.adult_gate_note' => 'Після підтвердження доступ діятиме протягом поточного сеансу.',
                'home.adult_gate_note_account' => 'Для зареєстрованого користувача підтвердження зберігається в акаунті; дата народження для цього не потрібна.',
                'home.footer_delivery' => 'Доставка',
                'home.footer_payment' => 'Оплата',
                'home.footer_returns' => 'Повернення',
                'home.footer_contacts' => 'Контакти'
            ],
            'ru' => [
                'home.hero_eyebrow' => 'Анабелька',
                'home.hero_title' => 'Для ежедневного комфорта, дома, отдыха и особенных моментов',
                'home.hero_text' => 'Бельё, чулочно-носочные изделия, домашняя одежда, купальники и другие направления в одном магазине.',
                'home.hero_catalog' => 'Перейти в каталог',
                'home.nav_catalog' => 'Каталог',
                'home.directions_title' => 'Направления магазина',
                'home.directions_text' => 'Выберите нужный раздел и переходите к категориям и товарам.',
                'home.direction_open' => 'Открыть раздел',
                'home.latest_title' => 'Новинки',
                'home.latest_text' => 'Последние товары, добавленные в каталог.',
                'home.product_open' => 'Посмотреть товар',
                'home.all_catalog' => 'Весь каталог',
                'home.empty_directions' => 'Направления магазина ещё не настроены.',
                'home.empty_products' => 'Новых товаров пока нет.',
                'home.adult_entry_title' => 'Интимные товары',
                'home.adult_entry_text' => 'Отдельный приватный раздел для совершеннолетних. Товары 18+ не смешиваются с обычными рекомендациями магазина.',
                'home.adult_open' => 'Перейти в раздел 18+',
                'home.adult_gate_title' => 'Подтверждение возраста',
                'home.adult_gate_heading' => 'Этот раздел предназначен только для совершеннолетних',
                'home.adult_gate_text' => 'Подтвердите, что вам исполнилось 18 лет, чтобы перейти в раздел.',
                'home.adult_confirm' => 'Мне уже есть 18 лет',
                'home.adult_leave' => 'Вернуться на главную',
                'home.adult_gate_note' => 'После подтверждения доступ будет действовать в течение текущего сеанса.',
                'home.adult_gate_note_account' => 'Для зарегистрированного пользователя подтверждение сохраняется в аккаунте; дата рождения для этого не требуется.',
                'home.footer_delivery' => 'Доставка',
                'home.footer_payment' => 'Оплата',
                'home.footer_returns' => 'Возврат',
                'home.footer_contacts' => 'Контакты'
            ],
            'en' => [
                'home.hero_eyebrow' => 'Anabelka',
                'home.hero_title' => 'For everyday comfort, home, leisure and special moments',
                'home.hero_text' => 'Lingerie, hosiery, homewear, swimwear and other categories in one store.',
                'home.hero_catalog' => 'Browse catalog',
                'home.nav_catalog' => 'Catalog',
                'home.directions_title' => 'Shop departments',
                'home.directions_text' => 'Choose a department and continue to its categories and products.',
                'home.direction_open' => 'Open department',
                'home.latest_title' => 'New arrivals',
                'home.latest_text' => 'The latest products added to the catalog.',
                'home.product_open' => 'View product',
                'home.all_catalog' => 'Full catalog',
                'home.empty_directions' => 'Store departments are not configured yet.',
                'home.empty_products' => 'There are no new products yet.',
                'home.adult_entry_title' => 'Intimate products',
                'home.adult_entry_text' => 'A separate private section for adults. 18+ products are not mixed into regular store recommendations.',
                'home.adult_open' => 'Enter the 18+ section',
                'home.adult_gate_title' => 'Age confirmation',
                'home.adult_gate_heading' => 'This section is intended for adults only',
                'home.adult_gate_text' => 'Confirm that you are at least 18 years old to enter this section.',
                'home.adult_confirm' => 'I am 18 or older',
                'home.adult_leave' => 'Return to homepage',
                'home.adult_gate_note' => 'After confirmation, access remains active for the current session.',
                'home.adult_gate_note_account' => 'For a registered user, confirmation is saved to the account; a date of birth is not required.',
                'home.footer_delivery' => 'Delivery',
                'home.footer_payment' => 'Payment',
                'home.footer_returns' => 'Returns',
                'home.footer_contacts' => 'Contacts'
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
