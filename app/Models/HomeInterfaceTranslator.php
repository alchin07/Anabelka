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
                'home.news_title' => 'Новини Анабельки',
                'home.all_news' => 'Усі новини',
                'home.news_empty' => 'Новин поки немає.',
                'home.reviews_title' => 'Свіжі відгуки',
                'home.all_reviews' => 'Усі відгуки',
                'home.reviews_empty' => 'Схвалених відгуків поки немає.',
                'home.gift_title' => 'Подарунковий сертифікат',
                'home.gift_text' => 'Готуємо електронний сертифікат Анабельки для подарунка іншій людині.',
                'home.gift_more' => 'Дізнатися більше',
                'home.gift_kicker' => 'Подарунок, який обирає одержувач',
                'home.gift_page_text' => 'Ми готуємо електронний подарунковий сертифікат Анабельки, який можна буде передати іншій людині. Деталі оформлення та використання з’являться після завершення окремого безпечного модуля сертифікатів.',
                'home.gift_coming_soon' => 'Сервіс готується. На цій сторінці поки немає оформлення замовлення чи видачі сертифіката.',
                'home.gift_back' => 'Повернутися на головну',
                'home.useful_title' => 'Корисне',
                'home.utility_news' => 'Новини',
                'home.utility_reviews' => 'Відгуки покупців',
                'home.utility_gifts' => 'Подарункові сертифікати',
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
                'home.news_title' => 'Новости Анабельки',
                'home.all_news' => 'Все новости',
                'home.news_empty' => 'Новостей пока нет.',
                'home.reviews_title' => 'Свежие отзывы',
                'home.all_reviews' => 'Все отзывы',
                'home.reviews_empty' => 'Одобренных отзывов пока нет.',
                'home.gift_title' => 'Подарочный сертификат',
                'home.gift_text' => 'Готовим электронный сертификат Анабельки для подарка другому человеку.',
                'home.gift_more' => 'Узнать больше',
                'home.gift_kicker' => 'Подарок, который выбирает получатель',
                'home.gift_page_text' => 'Мы готовим электронный подарочный сертификат Анабельки, который можно будет передать другому человеку. Детали оформления и использования появятся после завершения отдельного безопасного модуля сертификатов.',
                'home.gift_coming_soon' => 'Сервис готовится. На этой странице пока нет оформления заказа или выдачи сертификата.',
                'home.gift_back' => 'Вернуться на главную',
                'home.useful_title' => 'Полезное',
                'home.utility_news' => 'Новости',
                'home.utility_reviews' => 'Отзывы покупателей',
                'home.utility_gifts' => 'Подарочные сертификаты',
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
                'home.news_title' => 'Anabelka news',
                'home.all_news' => 'All news',
                'home.news_empty' => 'There is no news yet.',
                'home.reviews_title' => 'Latest reviews',
                'home.all_reviews' => 'All reviews',
                'home.reviews_empty' => 'There are no approved reviews yet.',
                'home.gift_title' => 'Gift certificate',
                'home.gift_text' => 'We are preparing an Anabelka electronic certificate that can be gifted to someone else.',
                'home.gift_more' => 'Learn more',
                'home.gift_kicker' => 'A gift chosen by the recipient',
                'home.gift_page_text' => 'We are preparing an Anabelka electronic gift certificate that can be transferred to another person. Ordering and usage details will appear after the separate secure certificate module is complete.',
                'home.gift_coming_soon' => 'The service is being prepared. This page does not yet issue certificates or create certificate orders.',
                'home.gift_back' => 'Return to homepage',
                'home.useful_title' => 'Useful links',
                'home.utility_news' => 'News',
                'home.utility_reviews' => 'Customer reviews',
                'home.utility_gifts' => 'Gift certificates',
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
