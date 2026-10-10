<?php

class AdminDashboardServiceRegistry
{
    private const GROUPS = [
        'sales' => 'Продажі',
        'customers' => 'Користувачі',
        'catalog' => 'Каталог',
        'content' => 'Контент',
        'translations' => 'Переклади',
        'system' => 'Система'
    ];

    private const SERVICES = [
        'orders' => [
            'label' => 'Замовлення',
            'description' => 'Звичайні та швидкі замовлення магазину.',
            'path' => '/admin/orders',
            'permission' => 'orders.view',
            'group' => 'sales',
            'icon_key' => 'orders',
            'allow_multiple' => false,
            'badge' => [
                'source' => 'admin_notifications',
                'channels' => ['orders'],
                'tone' => 'notification'
            ]
        ],
        'search' => [
            'label' => 'Пошук',
            'description' => 'Журнал пошукових запитів відвідувачів.',
            'path' => '/admin/search',
            'permission' => 'search.view',
            'group' => 'sales',
            'icon_key' => 'search',
            'allow_multiple' => false
        ],
        'users' => [
            'label' => 'Користувачі',
            'description' => 'Користувачі, запрошення та запити на ранг.',
            'path' => '/admin/users',
            'permission' => 'users.view',
            'group' => 'customers',
            'icon_key' => 'users',
            'allow_multiple' => false,
            'badge' => [
                'source' => 'admin_notifications',
                'channels' => ['new_users', 'rank_requests'],
                'tone' => 'notification'
            ]
        ],
        'ranks' => [
            'label' => 'Ранги',
            'description' => 'Ранги користувачів і правила їх доступу.',
            'path' => '/admin/ranks',
            'permission' => 'ranks.view',
            'group' => 'customers',
            'icon_key' => 'ranks',
            'allow_multiple' => false
        ],
        'products' => [
            'label' => 'Товари',
            'description' => 'Каталог товарів, залишки та редагування карток.',
            'path' => '/admin/products',
            'permission' => 'products.view',
            'group' => 'catalog',
            'icon_key' => 'products',
            'allow_multiple' => false
        ],
        'categories' => [
            'label' => 'Категорії',
            'description' => 'Дерево категорій і розділів магазину.',
            'path' => '/admin/categories',
            'permission' => 'categories.view',
            'group' => 'catalog',
            'icon_key' => 'categories',
            'allow_multiple' => false
        ],
        'delivery' => [
            'label' => 'Доставка',
            'description' => 'Способи, служби та параметри доставки.',
            'path' => '/admin/delivery',
            'permission' => 'delivery.view',
            'group' => 'catalog',
            'icon_key' => 'delivery',
            'allow_multiple' => false
        ],
        'languages' => [
            'label' => 'Мови',
            'description' => 'Активні мови та мовні налаштування.',
            'path' => '/admin/languages',
            'permission' => 'languages.view',
            'group' => 'translations',
            'icon_key' => 'languages',
            'allow_multiple' => false
        ],
        'translations' => [
            'label' => 'Переклади',
            'description' => 'Черга перекладів і тексти, що потребують уваги.',
            'path' => '/admin/translations',
            'permission' => 'translations.view',
            'group' => 'translations',
            'icon_key' => 'translations',
            'allow_multiple' => false,
            'badge' => [
                'source' => 'admin_notifications',
                'channels' => ['translations'],
                'tone' => 'notification'
            ]
        ],
        'ai_translation' => [
            'label' => 'ШІ-переклад',
            'description' => 'Провайдери ШІ, API-ключі та перевірка підключення.',
            'path' => '/admin/ai-translation',
            'permission' => 'ai_translation.view',
            'group' => 'translations',
            'icon_key' => 'ai_translation',
            'allow_multiple' => false
        ],
        'news' => [
            'label' => 'Новини',
            'description' => 'Новини Анабельки та їх публікація.',
            'path' => '/admin/news',
            'permission' => 'news.view',
            'group' => 'content',
            'icon_key' => 'news',
            'allow_multiple' => false
        ],
        'reviews' => [
            'label' => 'Відгуки',
            'description' => 'Модерація відгуків покупців.',
            'path' => '/admin/reviews',
            'permission' => 'reviews.view',
            'group' => 'content',
            'icon_key' => 'reviews',
            'allow_multiple' => false
        ],
        'mobile_navigation' => [
            'label' => 'Мобільне меню',
            'description' => 'Пункти нижньої мобільної навігації.',
            'path' => '/admin/mobile-navigation',
            'permission' => 'mobile_navigation.view',
            'group' => 'content',
            'icon_key' => 'mobile_navigation',
            'allow_multiple' => false
        ],
        'home_page' => [
            'label' => 'Головна сторінка',
            'description' => 'Конструктор публічної головної сторінки.',
            'path' => '/admin/home-page',
            'permission' => 'home_page.view',
            'group' => 'content',
            'icon_key' => 'home_page',
            'allow_multiple' => false
        ],
        'administrators' => [
            'label' => 'Адміністратори',
            'description' => 'Адміністратори, ролі та права доступу.',
            'path' => '/admin/administrators',
            'permission' => 'administrators.view',
            'group' => 'system',
            'icon_key' => 'administrators',
            'allow_multiple' => false
        ],
        'audit' => [
            'label' => 'Журнал дій',
            'description' => 'Журнал адміністративних дій.',
            'path' => '/admin/audit',
            'permission' => 'audit.view',
            'group' => 'system',
            'icon_key' => 'audit',
            'allow_multiple' => false
        ],
        'vip_price_views' => [
            'label' => 'Перегляди VIP-цін',
            'description' => 'Журнал переглядів захищених VIP-цін.',
            'path' => '/admin/vip-price-views',
            'permission' => 'vip_prices.view',
            'group' => 'system',
            'icon_key' => 'vip_price_views',
            'allow_multiple' => false
        ],
        'backup' => [
            'label' => 'Резервні копії',
            'description' => 'Створення повної резервної копії Анабельки.',
            'path' => '/admin/system/backup',
            'permission' => 'admin.access',
            'group' => 'system',
            'icon_key' => 'backup',
            'allow_multiple' => false,
            'roles' => ['owner']
        ],
        'system_errors' => [
            'label' => 'Системні помилки',
            'description' => 'Журнал системних помилок та їх статуси.',
            'path' => '/admin/system/errors',
            'permission' => 'admin.access',
            'group' => 'system',
            'icon_key' => 'system_errors',
            'allow_multiple' => false,
            'roles' => ['owner'],
            'badge' => [
                'source' => 'system_errors',
                'tone' => 'error'
            ]
        ],
        'system_error_notifications' => [
            'label' => 'Сповіщення про помилки',
            'description' => 'Зовнішні сповіщення про системні помилки.',
            'path' => '/admin/system/error-external-notifications',
            'permission' => 'admin.access',
            'group' => 'system',
            'icon_key' => 'system_error_notifications',
            'allow_multiple' => false,
            'roles' => ['owner']
        ]
    ];


    public static function groups()
    {
        return self::GROUPS;
    }


    public static function catalog()
    {
        $result = [];

        foreach (self::SERVICES as $key => $definition) {
            $result[$key] = self::normalize(
                (string) $key,
                $definition
            );
        }

        return $result;
    }


    public static function find($serviceKey)
    {
        $serviceKey = self::normalizeKey($serviceKey);
        $definition = self::SERVICES[$serviceKey] ?? null;

        if (!is_array($definition)) {
            return null;
        }

        return self::normalize(
            $serviceKey,
            $definition
        );
    }


    public static function requireService($serviceKey)
    {
        $service = self::find($serviceKey);

        if (!$service) {
            throw new InvalidArgumentException(
                'Невідома служба адмін-панелі.'
            );
        }

        return $service;
    }


    public static function available()
    {
        $result = [];

        foreach (self::catalog() as $key => $service) {
            if (self::canAccess($service)) {
                $result[$key] = $service;
            }
        }

        return $result;
    }


    public static function availableWithBadges()
    {
        $services = self::available();

        if (empty($services)) {
            return [];
        }

        $notificationSummary = self::notificationSummary();
        $adminUserId = class_exists('AdminAccess')
            ? AdminAccess::currentId()
            : 0;

        foreach ($services as &$service) {
            $service['badge_count'] = self::badgeCount(
                $service,
                $notificationSummary,
                $adminUserId
            );
            $service['has_badge'] = !empty($service['badge'])
                && $service['badge_count'] > 0;
        }
        unset($service);

        return $services;
    }


    public static function canUse($serviceKey)
    {
        $service = self::find($serviceKey);

        return $service
            ? self::canAccess($service)
            : false;
    }


    private static function normalize($key, array $definition)
    {
        $path = (string) ($definition['path'] ?? '');
        $group = (string) ($definition['group'] ?? '');

        return [
            'key' => (string) $key,
            'label' => (string) ($definition['label'] ?? $key),
            'description' => (string) ($definition['description'] ?? ''),
            'path' => $path,
            'url' => '/Anabelka' . $path,
            'permission' => (string) (
                $definition['permission'] ?? 'admin.access'
            ),
            'group' => $group,
            'group_label' => (string) (
                self::GROUPS[$group] ?? $group
            ),
            'icon_key' => (string) (
                $definition['icon_key'] ?? 'service'
            ),
            'allow_multiple' => !empty(
                $definition['allow_multiple']
            ),
            'roles' => array_values(array_filter(
                is_array($definition['roles'] ?? null)
                    ? $definition['roles']
                    : [],
                'is_string'
            )),
            'badge' => is_array($definition['badge'] ?? null)
                ? $definition['badge']
                : null
        ];
    }


    private static function canAccess(array $service)
    {
        if (!class_exists('AdminAccess')) {
            return false;
        }

        $admin = AdminAccess::current();

        if (!$admin) {
            return false;
        }

        $roles = is_array($service['roles'] ?? null)
            ? $service['roles']
            : [];

        if (
            !empty($roles)
            && !in_array(
                (string) ($admin['role_slug'] ?? ''),
                $roles,
                true
            )
        ) {
            return false;
        }

        $permission = trim(
            (string) ($service['permission'] ?? '')
        );

        return $permission !== ''
            && AdminAccess::can($permission);
    }


    private static function notificationSummary()
    {
        if (!class_exists('AdminNotificationCenter')) {
            return [
                'by_key' => []
            ];
        }

        try {
            $summary = AdminNotificationCenter::summary();

            return is_array($summary)
                ? $summary
                : ['by_key' => []];
        } catch (Throwable $e) {
            return [
                'by_key' => []
            ];
        }
    }


    private static function badgeCount(
        array $service,
        array $notificationSummary,
        $adminUserId
    ) {
        $badge = is_array($service['badge'] ?? null)
            ? $service['badge']
            : [];
        $source = (string) ($badge['source'] ?? '');

        if ($source === 'admin_notifications') {
            $byKey = is_array(
                $notificationSummary['by_key'] ?? null
            )
                ? $notificationSummary['by_key']
                : [];
            $count = 0;

            foreach ($badge['channels'] ?? [] as $channel) {
                $count += max(
                    0,
                    (int) ($byKey[(string) $channel] ?? 0)
                );
            }

            return $count;
        }

        if (
            $source === 'system_errors'
            && (int) $adminUserId > 0
            && class_exists('SystemErrorNotification')
        ) {
            try {
                return max(
                    0,
                    (int) SystemErrorNotification::unreadCount(
                        (int) $adminUserId
                    )
                );
            } catch (Throwable $e) {
                return 0;
            }
        }

        return 0;
    }


    private static function normalizeKey($serviceKey)
    {
        return strtolower(trim((string) $serviceKey));
    }
}
