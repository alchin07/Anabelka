import assert from 'node:assert/strict';
import test from 'node:test';
import {phpJson} from './helpers/php-probe.mjs';

// Preflight lookups must fail through the normal JSON envelope, not leak a
// PHP HTML/fatal page before CategoryManager begins a mutation.
for (const [operation, failure] of [
    ['update', 'language'],
    ['update', 'translator'],
    ['delete', 'translator']
]) {
    test(`AdminCategoryController.${operation} catches ${failure} preflight failures`, () => {
        const result = phpJson(`
            class Controller {}
            class CategoryManager {
                public static $operations = 0;
                public static function update(...$args) { self::$operations++; }
                public static function delete(...$args) { self::$operations++; }
            }
            class Language {
                public static function active() {
                    if ('${failure}' === 'language') {
                        throw new RuntimeException('Private language lookup details');
                    }
                    return [];
                }
            }
            class CategoryTranslator {
                public static function getForCategory($id) {
                    if ('${failure}' === 'translator') {
                        throw new RuntimeException('Private translation lookup details');
                    }
                    return [];
                }
            }
            require 'app/Controllers/AdminCategoryController.php';
            $_POST = ['category_id' => 3];
            ob_start();
            register_shutdown_function(static function () {
                $raw = ob_get_clean();
                echo json_encode([
                    'status' => http_response_code(),
                    'content' => json_decode($raw, true, 512, JSON_THROW_ON_ERROR),
                    'mutations' => CategoryManager::$operations
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            });
            $controller = (new ReflectionClass(AdminCategoryController::class))
                ->newInstanceWithoutConstructor();
            $controller->${operation}();
        `);
        assert.deepEqual(result, {
            status: 500,
            content: {
                success: false,
                message: 'Не вдалося виконати операцію з категорією. Перевірте журнал помилок.'
            },
            mutations: 0
        });
    });
}
