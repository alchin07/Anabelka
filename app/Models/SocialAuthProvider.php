<?php

class SocialAuthProvider
{
    private const PROVIDERS = [
        'google' => [
            'label' => 'Google',
            'mark' => 'G',
            'route' => '/Anabelka/auth/google',
            'callback_path' => '/Anabelka/auth/google/callback',
            'driver' => 'GoogleOAuthProvider',
            'default_enabled' => true,
            'default_sort_order' => 10
        ],
        'facebook' => [
            'label' => 'Facebook',
            'mark' => 'f',
            'route' => '/Anabelka/auth/facebook',
            'callback_path' => '/Anabelka/auth/facebook/callback',
            'driver' => 'FacebookOAuthProvider',
            'default_enabled' => false,
            'default_sort_order' => 20
        ],
        'apple' => [
            'label' => 'Apple',
            'mark' => 'A',
            'route' => '/Anabelka/auth/apple',
            'callback_path' => '/Anabelka/auth/apple/callback',
            'driver' => 'AppleOAuthProvider',
            'default_enabled' => false,
            'default_sort_order' => 30
        ]
    ];


    public static function all()
    {
        $providers = [];

        foreach (self::PROVIDERS as $code => $definition) {
            $providers[] = self::buildProvider($code, $definition);
        }

        usort($providers, function ($left, $right) {
            $leftOrder = (int) ($left['sort_order'] ?? 0);
            $rightOrder = (int) ($right['sort_order'] ?? 0);

            if ($leftOrder === $rightOrder) {
                return strcmp(
                    (string) ($left['code'] ?? ''),
                    (string) ($right['code'] ?? '')
                );
            }

            return $leftOrder <=> $rightOrder;
        });

        return $providers;
    }


    public static function publicProviders()
    {
        return array_values(array_filter(
            self::all(),
            function ($provider) {
                return !empty($provider['enabled'])
                    && !empty($provider['configured'])
                    && !empty($provider['route']);
            }
        ));
    }


    public static function ensureAdminPermissions()
    {
        if (!class_exists('AdminAccess')) {
            return;
        }

        AdminAccess::ensureSchema();
        $db = Database::connect();

        $permissions = [
            ['social_auth.view', 'Перегляд налаштувань соціальної авторизації', 'security', 125],
            ['social_auth.manage', 'Керування соціальною авторизацією', 'security', 126]
        ];

        $insertPermission = $db->prepare("
            INSERT IGNORE INTO admin_permissions
            (permission_key, name, group_key, sort_order)
            VALUES
            (:permission_key, :name, :group_key, :sort_order)
        ");

        foreach ($permissions as $permission) {
            $insertPermission->execute([
                'permission_key' => $permission[0],
                'name' => $permission[1],
                'group_key' => $permission[2],
                'sort_order' => $permission[3]
            ]);
        }

        $roleSlugs = ['owner', 'store_owner', 'administrator'];
        $roleStmt = $db->prepare("
            SELECT id
            FROM admin_roles
            WHERE slug = :slug
            LIMIT 1
        ");
        $grantStmt = $db->prepare("
            INSERT IGNORE INTO admin_role_permissions
            (role_id, permission_key)
            VALUES
            (:role_id, :permission_key)
        ");

        foreach ($roleSlugs as $roleSlug) {
            $roleStmt->execute(['slug' => $roleSlug]);
            $roleId = (int) $roleStmt->fetchColumn();

            if ($roleId <= 0) {
                continue;
            }

            foreach ($permissions as $permission) {
                $grantStmt->execute([
                    'role_id' => $roleId,
                    'permission_key' => $permission[0]
                ]);
            }
        }
    }


    public static function exists($provider)
    {
        $provider = self::normalize($provider);
        return isset(self::PROVIDERS[$provider]);
    }


    public static function get($provider)
    {
        $provider = self::normalize($provider);

        if (!isset(self::PROVIDERS[$provider])) {
            throw new InvalidArgumentException('Невідомий провайдер соціальної авторизації.');
        }

        return self::buildProvider($provider, self::PROVIDERS[$provider]);
    }


    public static function label($provider)
    {
        try {
            return (string) self::get($provider)['label'];
        } catch (Throwable $e) {
            return ucfirst(self::normalize($provider));
        }
    }


    public static function driverClass($provider)
    {
        return (string) (self::get($provider)['driver'] ?? '');
    }


    public static function isEnabled($provider)
    {
        return !empty(self::get($provider)['enabled']);
    }


    public static function isConfigured($provider)
    {
        return !empty(self::get($provider)['configured']);
    }


    public static function isAvailable($provider)
    {
        $provider = self::get($provider);

        return !empty($provider['enabled'])
            && !empty($provider['configured']);
    }


    public static function setEnabled($provider, $enabled)
    {
        $provider = self::normalize($provider);

        if (!isset(self::PROVIDERS[$provider])) {
            throw new InvalidArgumentException('Невідомий провайдер соціальної авторизації.');
        }

        return AppSetting::set(
            self::enabledSettingKey($provider),
            $enabled ? '1' : '0'
        );
    }


    public static function move($provider, $direction)
    {
        $provider = self::normalize($provider);
        $direction = strtolower(trim((string) $direction));

        if (!isset(self::PROVIDERS[$provider])) {
            throw new InvalidArgumentException('Невідомий провайдер соціальної авторизації.');
        }

        if (!in_array($direction, ['up', 'down'], true)) {
            throw new InvalidArgumentException('Некоректний напрямок переміщення.');
        }

        $providers = self::all();
        $currentIndex = null;

        foreach ($providers as $index => $item) {
            if (($item['code'] ?? '') === $provider) {
                $currentIndex = $index;
                break;
            }
        }

        if ($currentIndex === null) {
            return false;
        }

        $targetIndex = $direction === 'up'
            ? $currentIndex - 1
            : $currentIndex + 1;

        if (!isset($providers[$targetIndex])) {
            return false;
        }

        $currentOrder = (int) $providers[$currentIndex]['sort_order'];
        $targetOrder = (int) $providers[$targetIndex]['sort_order'];

        AppSetting::set(
            self::sortSettingKey($providers[$currentIndex]['code']),
            (string) $targetOrder
        );
        AppSetting::set(
            self::sortSettingKey($providers[$targetIndex]['code']),
            (string) $currentOrder
        );

        return true;
    }


    private static function buildProvider($code, array $definition)
    {
        $defaultEnabled = !empty($definition['default_enabled']);
        $enabledRaw = AppSetting::get(
            self::enabledSettingKey($code),
            $defaultEnabled ? '1' : '0'
        );
        $sortOrder = (int) AppSetting::get(
            self::sortSettingKey($code),
            (string) ($definition['default_sort_order'] ?? 0)
        );
        $driver = trim((string) ($definition['driver'] ?? ''));
        $configured = false;

        if ($driver !== '' && class_exists($driver)) {
            try {
                $configured = method_exists($driver, 'isConfigured')
                    && (bool) $driver::isConfigured();
            } catch (Throwable $e) {
                $configured = false;
            }
        }

        return [
            'code' => $code,
            'label' => (string) ($definition['label'] ?? $code),
            'mark' => (string) ($definition['mark'] ?? ''),
            'route' => (string) ($definition['route'] ?? ''),
            'callback_path' => (string) ($definition['callback_path'] ?? ''),
            'driver' => $driver,
            'enabled' => self::toBool($enabledRaw),
            'configured' => $configured,
            'available' => self::toBool($enabledRaw) && $configured,
            'sort_order' => $sortOrder
        ];
    }


    private static function enabledSettingKey($provider)
    {
        return 'social_auth.' . $provider . '.enabled';
    }


    private static function sortSettingKey($provider)
    {
        return 'social_auth.' . $provider . '.sort_order';
    }


    private static function normalize($provider)
    {
        return strtolower(trim((string) $provider));
    }


    private static function toBool($value)
    {
        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }
}
