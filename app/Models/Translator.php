<?php

class Translator
{
    private static $schemaReady = false;
    private static $currentLanguage = null;
    private static $cache = [];


    private static function ensureTable()
    {
        if (self::$schemaReady) {
            return;
        }

        // Гарантируем, что таблица языков уже создана.
        Language::all();

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS interface_translations
            (
                translation_key VARCHAR(190) NOT NULL,
                language_code VARCHAR(10) NOT NULL,
                value TEXT NOT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'manual',
                status VARCHAR(20) NOT NULL DEFAULT 'approved',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (translation_key, language_code),
                KEY idx_interface_translations_language (language_code)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::seedBaseTranslations($db);

        self::$schemaReady = true;
    }


    private static function seedBaseTranslations(PDO $db)
    {
        /*
         * Стартовый словарь интерфейса.
         * INSERT IGNORE принципиален: ручные и будущие ИИ-переводы
         * из админ-панели не должны перезаписываться кодом.
         */
        $translations = [
            'uk' => [
                'header.cart' => 'Кошик',
                'header.login' => 'Увійти',
                'header.logout' => 'Вийти',
                'header.register' => 'Реєстрація',
                'language.switcher_label' => 'Мова сайту',

                'cart.title' => 'Кошик',
                'cart.continue_shopping' => 'Продовжити покупки',
                'cart.empty' => 'Кошик поки порожній.',
                'cart.price' => 'Ціна',
                'cart.size' => 'Розмір',
                'cart.sum' => 'Сума',
                'cart.remove' => 'Видалити',
                'cart.total' => 'Разом',
                'cart.checkout' => 'Оформити замовлення',
                'cart.quick_order' => 'Швидке замовлення',
                'cart.error_change' => 'Не вдалося змінити кошик.',
                'cart.error_quantity' => 'Не вдалося змінити кількість.',
                'cart.error_remove' => 'Не вдалося видалити товар.',

                'quick.title' => 'Швидке замовлення',
                'quick.intro' => 'Залиште ім’я та номер телефону. Ми зв’яжемося з вами для уточнення доставки.',
                'quick.name' => 'Ім’я',
                'quick.phone' => 'Номер телефону',
                'quick.comment' => 'Коментар',
                'quick.total' => 'Сума замовлення',
                'quick.submit' => 'Надіслати швидке замовлення',
                'quick.success_page_title' => 'Швидке замовлення прийнято',
                'quick.accepted' => 'Замовлення прийнято',
                'quick.thanks' => 'Дякуємо',
                'quick.contact_before' => 'Ми зв’яжемося з вами за номером',
                'quick.contact_after' => 'для уточнення деталей замовлення та доставки.',
                'quick.continue_shopping' => 'Продовжити покупки',
                'quick.error_required' => 'Заповніть ім’я та номер телефону.',
                'quick.error_empty' => 'Кошик порожній.',

                'checkout.title' => 'Оформлення замовлення',
                'checkout.customer_data' => 'Дані покупця',
                'checkout.name' => 'Ім’я',
                'checkout.email' => 'Email',
                'checkout.phone' => 'Телефон',
                'checkout.delivery_method' => 'Спосіб доставки',
                'checkout.choose_delivery_service' => 'Оберіть службу доставки',
                'checkout.country' => 'Країна',
                'checkout.city' => 'Місто',
                'checkout.address' => 'Адреса',
                'checkout.address_placeholder' => 'Введіть адресу',
                'checkout.postcode' => 'Поштовий індекс',
                'checkout.comment' => 'Коментар до замовлення',
                'checkout.required_fields' => '— обов’язкові поля',
                'checkout.continue' => 'Продовжити оформлення',
                'checkout.branch' => 'Відділення',
                'checkout.branch_placeholder' => 'Номер або адреса відділення',
                'checkout.parcel_locker' => 'Поштомат',
                'checkout.parcel_locker_placeholder' => 'Номер поштомата',
                'checkout.delivery_address' => 'Адреса доставки',
                'checkout.delivery_address_placeholder' => 'Вулиця, будинок, квартира'
            ],
            'ru' => [
                'header.cart' => 'Корзина',
                'header.login' => 'Войти',
                'header.logout' => 'Выйти',
                'header.register' => 'Регистрация',
                'language.switcher_label' => 'Язык сайта',

                'cart.title' => 'Корзина',
                'cart.continue_shopping' => 'Продолжить покупки',
                'cart.empty' => 'Корзина пока пуста.',
                'cart.price' => 'Цена',
                'cart.size' => 'Размер',
                'cart.sum' => 'Сумма',
                'cart.remove' => 'Удалить',
                'cart.total' => 'Итого',
                'cart.checkout' => 'Оформить заказ',
                'cart.quick_order' => 'Быстрый заказ',
                'cart.error_change' => 'Не удалось изменить корзину.',
                'cart.error_quantity' => 'Не удалось изменить количество.',
                'cart.error_remove' => 'Не удалось удалить товар.',

                'quick.title' => 'Быстрый заказ',
                'quick.intro' => 'Оставьте имя и номер телефона. Мы свяжемся с вами для уточнения доставки.',
                'quick.name' => 'Имя',
                'quick.phone' => 'Номер телефона',
                'quick.comment' => 'Комментарий',
                'quick.total' => 'Сумма заказа',
                'quick.submit' => 'Отправить быстрый заказ',
                'quick.success_page_title' => 'Быстрый заказ принят',
                'quick.accepted' => 'Заказ принят',
                'quick.thanks' => 'Спасибо',
                'quick.contact_before' => 'Мы свяжемся с вами по номеру',
                'quick.contact_after' => 'для уточнения деталей заказа и доставки.',
                'quick.continue_shopping' => 'Продолжить покупки',
                'quick.error_required' => 'Заполните имя и номер телефона.',
                'quick.error_empty' => 'Корзина пуста.',

                'checkout.title' => 'Оформление заказа',
                'checkout.customer_data' => 'Данные покупателя',
                'checkout.name' => 'Имя',
                'checkout.email' => 'Email',
                'checkout.phone' => 'Телефон',
                'checkout.delivery_method' => 'Способ доставки',
                'checkout.choose_delivery_service' => 'Выберите службу доставки',
                'checkout.country' => 'Страна',
                'checkout.city' => 'Город',
                'checkout.address' => 'Адрес',
                'checkout.address_placeholder' => 'Введите адрес',
                'checkout.postcode' => 'Почтовый индекс',
                'checkout.comment' => 'Комментарий к заказу',
                'checkout.required_fields' => '— обязательные поля',
                'checkout.continue' => 'Продолжить оформление',
                'checkout.branch' => 'Отделение',
                'checkout.branch_placeholder' => 'Номер или адрес отделения',
                'checkout.parcel_locker' => 'Почтомат',
                'checkout.parcel_locker_placeholder' => 'Номер почтомата',
                'checkout.delivery_address' => 'Адрес доставки',
                'checkout.delivery_address_placeholder' => 'Улица, дом, квартира'
            ],
            'en' => [
                'header.cart' => 'Cart',
                'header.login' => 'Sign in',
                'header.logout' => 'Sign out',
                'header.register' => 'Register',
                'language.switcher_label' => 'Site language',

                'cart.title' => 'Cart',
                'cart.continue_shopping' => 'Continue shopping',
                'cart.empty' => 'Your cart is empty.',
                'cart.price' => 'Price',
                'cart.size' => 'Size',
                'cart.sum' => 'Subtotal',
                'cart.remove' => 'Remove',
                'cart.total' => 'Total',
                'cart.checkout' => 'Checkout',
                'cart.quick_order' => 'Quick order',
                'cart.error_change' => 'Could not update the cart.',
                'cart.error_quantity' => 'Could not update the quantity.',
                'cart.error_remove' => 'Could not remove the item.',

                'quick.title' => 'Quick order',
                'quick.intro' => 'Leave your name and phone number. We will contact you to confirm delivery details.',
                'quick.name' => 'Name',
                'quick.phone' => 'Phone number',
                'quick.comment' => 'Comment',
                'quick.total' => 'Order total',
                'quick.submit' => 'Send quick order',
                'quick.success_page_title' => 'Quick order received',
                'quick.accepted' => 'Order received',
                'quick.thanks' => 'Thank you',
                'quick.contact_before' => 'We will contact you at',
                'quick.contact_after' => 'to confirm the order and delivery details.',
                'quick.continue_shopping' => 'Continue shopping',
                'quick.error_required' => 'Enter your name and phone number.',
                'quick.error_empty' => 'Your cart is empty.',

                'checkout.title' => 'Checkout',
                'checkout.customer_data' => 'Customer details',
                'checkout.name' => 'Name',
                'checkout.email' => 'Email',
                'checkout.phone' => 'Phone',
                'checkout.delivery_method' => 'Delivery method',
                'checkout.choose_delivery_service' => 'Choose a delivery service',
                'checkout.country' => 'Country',
                'checkout.city' => 'City',
                'checkout.address' => 'Address',
                'checkout.address_placeholder' => 'Enter address',
                'checkout.postcode' => 'Postal code',
                'checkout.comment' => 'Order comment',
                'checkout.required_fields' => '— required fields',
                'checkout.continue' => 'Continue checkout',
                'checkout.branch' => 'Branch',
                'checkout.branch_placeholder' => 'Branch number or address',
                'checkout.parcel_locker' => 'Parcel locker',
                'checkout.parcel_locker_placeholder' => 'Parcel locker number',
                'checkout.delivery_address' => 'Delivery address',
                'checkout.delivery_address_placeholder' => 'Street, house, apartment'
            ]
        ];

        $stmt = $db->prepare("
            INSERT IGNORE INTO interface_translations
            (
                translation_key,
                language_code,
                value,
                source,
                status
            )
            VALUES
            (
                :translation_key,
                :language_code,
                :value,
                'manual',
                'approved'
            )
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
    }


    public static function currentLanguage()
    {
        self::ensureTable();

        if (self::$currentLanguage !== null) {
            return self::$currentLanguage;
        }

        $sessionCode = strtolower(
            trim((string) ($_SESSION['language_code'] ?? ''))
        );

        if ($sessionCode !== '') {
            $language = Language::findByCode($sessionCode);

            if ($language && !empty($language['is_active'])) {
                self::$currentLanguage = $language;
                return $language;
            }
        }

        $language = Language::getDefault();

        if (!$language) {
            $language = Language::findByCode(Language::SOURCE_CODE);
        }

        if ($language) {
            $_SESSION['language_code'] = $language['code'];
        }

        self::$currentLanguage = $language ?: [
            'code' => Language::SOURCE_CODE,
            'locale' => 'uk-UA',
            'name' => 'Українська',
            'short_name' => 'UA'
        ];

        return self::$currentLanguage;
    }


    public static function setCurrentLanguage($code)
    {
        self::ensureTable();

        $code = strtolower(trim((string) $code));
        $language = Language::findByCode($code);

        if (!$language || empty($language['is_active'])) {
            throw new RuntimeException('Выбранный язык недоступен.');
        }

        $_SESSION['language_code'] = $language['code'];
        self::$currentLanguage = $language;
        self::$cache = [];

        return $language;
    }


    public static function activeLanguages()
    {
        self::ensureTable();
        return Language::active();
    }


    public static function t($key, $fallback = '')
    {
        self::ensureTable();

        $key = trim((string) $key);

        if ($key === '') {
            return (string) $fallback;
        }

        $language = self::currentLanguage();
        $code = (string) ($language['code'] ?? Language::SOURCE_CODE);

        $value = self::findValue($key, $code);

        if ($value !== null) {
            return $value;
        }

        // Любой отсутствующий перевод возвращается к исходному украинскому.
        if ($code !== Language::SOURCE_CODE) {
            $sourceValue = self::findValue(
                $key,
                Language::SOURCE_CODE
            );

            if ($sourceValue !== null) {
                return $sourceValue;
            }
        }

        return $fallback !== ''
            ? (string) $fallback
            : $key;
    }


    public static function deleteForLanguage($code)
    {
        self::ensureTable();

        $code = strtolower(trim((string) $code));

        if ($code === '' || $code === Language::SOURCE_CODE) {
            return false;
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            DELETE FROM interface_translations
            WHERE language_code = :language_code
        ");

        $result = $stmt->execute([
            'language_code' => $code
        ]);

        self::$cache = [];

        return $result;
    }


    public static function getForKey($key)
    {
        self::ensureTable();

        $key = trim((string) $key);

        if ($key === '' || strlen($key) > 190) {
            return [];
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                language_code,
                value,
                source,
                status
            FROM interface_translations
            WHERE translation_key = :translation_key
        ");

        $stmt->execute([
            'translation_key' => $key
        ]);

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = strtolower(
                trim((string) ($row['language_code'] ?? ''))
            );

            if ($code !== '') {
                $result[$code] = $row;
            }
        }

        return $result;
    }


    public static function saveForKey(
        $key,
        $languageCode,
        $value,
        $source = 'manual',
        $status = 'approved'
    ) {
        self::ensureTable();

        $key = trim((string) $key);
        $languageCode = strtolower(
            trim((string) $languageCode)
        );
        $value = trim((string) $value);
        $source = TranslationWorkflow::normalizeSource($source);

        if (
            $key === ''
            || strlen($key) > 190
            || $languageCode === ''
        ) {
            throw new InvalidArgumentException(
                'Некоректні дані перекладу інтерфейсу.'
            );
        }

        $language = Language::findByCode($languageCode);

        if (!$language || empty($language['is_active'])) {
            throw new InvalidArgumentException(
                'Мова перекладу недоступна.'
            );
        }

        if (
            $languageCode === Language::SOURCE_CODE
            && $value === ''
        ) {
            throw new InvalidArgumentException(
                'Український вихідний текст не може бути порожнім.'
            );
        }

        /*
         * Порожній цільовий текст зберігаємо як draft, а не видаляємо.
         * Інакше INSERT IGNORE базового словника відновить старе значення.
         * Draft не використовується на сайті, тому спрацює fallback на UK.
         */
        $status = $languageCode === Language::SOURCE_CODE
            ? TranslationWorkflow::STATUS_APPROVED
            : TranslationWorkflow::normalizeStatus(
                $status,
                $value !== ''
            );

        $db = Database::connect();

        $stmt = $db->prepare("
            INSERT INTO interface_translations
            (
                translation_key,
                language_code,
                value,
                source,
                status
            )
            VALUES
            (
                :translation_key,
                :language_code,
                :value,
                :source,
                :status
            )
            ON DUPLICATE KEY UPDATE
                value = VALUES(value),
                source = VALUES(source),
                status = VALUES(status)
        ");

        $result = $stmt->execute([
            'translation_key' => $key,
            'language_code' => $languageCode,
            'value' => $value,
            'source' => $source,
            'status' => $status
        ]);

        self::$cache = [];

        return $result;
    }


    public static function markOutdatedForKey($key)
    {
        self::ensureTable();

        $key = trim((string) $key);

        if ($key === '' || strlen($key) > 190) {
            return false;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            UPDATE interface_translations
            SET status = 'outdated'
            WHERE translation_key = :translation_key
              AND language_code <> :source_language
              AND TRIM(value) <> ''
        ");

        $result = $stmt->execute([
            'translation_key' => $key,
            'source_language' => Language::SOURCE_CODE
        ]);

        self::$cache = [];

        return $result;
    }


    private static function findValue($key, $code)
    {
        $cacheKey = $code . ':' . $key;

        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT value
            FROM interface_translations
            WHERE translation_key = :translation_key
              AND language_code = :language_code
              AND status IN ('approved', 'outdated')
            LIMIT 1
        ");

        $stmt->execute([
            'translation_key' => $key,
            'language_code' => $code
        ]);

        $value = $stmt->fetchColumn();

        self::$cache[$cacheKey] =
            $value === false
                ? null
                : (string) $value;

        return self::$cache[$cacheKey];
    }
}
