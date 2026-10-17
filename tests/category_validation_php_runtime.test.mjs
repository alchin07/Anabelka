import assert from 'node:assert/strict';
import test from 'node:test';
import {phpJson} from './helpers/php-probe.mjs';

const requiredMessage = 'Назва категорії обов’язкова.';
const lengthMessage = 'Назва категорії не може бути довшою за 150 символів.';

// Tripwires prove that invalid manager inputs never reach persistence or
// translation lookup. Only the real manager/controller code is under test;
// application bootstrap, authentication and a database are deliberately absent.
const dependencies = `
    class Database {
        public static $calls = 0;
        public static function connect() {
            self::$calls++;
            throw new RuntimeException('Unexpected database access in validation test.');
        }
    }
    class CategoryTranslator {
        public static $calls = 0;
        public static function getForCategory($categoryId) {
            self::$calls++;
            return [];
        }
    }
    require 'app/Services/CategoryManager.php';
`;

function phpInput(input) {
    const encoded = Buffer.from(JSON.stringify(input)).toString('base64');
    return `json_decode(base64_decode('${encoded}'), true, 512, JSON_THROW_ON_ERROR)`;
}

const invalidInputs = [
    ['missing name', {}, requiredMessage],
    ['empty name', {name: ''}, requiredMessage],
    ['ASCII whitespace name', {name: ' \t\r\n\v\0 '}, requiredMessage],
    ['151 Ukrainian characters', {name: 'Ї'.repeat(151)}, lengthMessage],
    ['151 astral Unicode characters', {name: '🧵'.repeat(151)}, lengthMessage],
    ['151 code points with combining marks', {name: 'і\u0308'.repeat(75) + 'і'}, lengthMessage]
];

for (const operation of ['create', 'update']) {
    for (const [label, input, message] of invalidInputs) {
        test(`CategoryManager.${operation} rejects ${label} before database access`, () => {
            const result = phpJson(`
                ${dependencies}
                $input = ${phpInput(input)};
                try {
                    ${operation === 'create'
                        ? 'CategoryManager::create($input);'
                        : 'CategoryManager::update(37, $input, []);'}
                    $result = ['exception' => null, 'message' => null];
                } catch (Throwable $error) {
                    $result = [
                        'exception' => get_class($error),
                        'message' => $error->getMessage()
                    ];
                }
                $result['databaseCalls'] = Database::$calls;
                $result['translationCalls'] = CategoryTranslator::$calls;
                echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            `);

            assert.deepEqual(result, {
                exception: 'DomainException',
                message,
                databaseCalls: 0,
                translationCalls: 0
            });
        });
    }
}

// Valid-name checks invoke the production validator directly via reflection.
// They establish trimming/counting boundaries, not successful DB mutations.
const validNames = [
    ['trims surrounding ASCII whitespace', ' \tБілизна\r\n ', 'Білизна'],
    ['accepts 150 Ukrainian characters after trimming', ` \t${'Ї'.repeat(150)}\n`, 'Ї'.repeat(150)],
    ['accepts 150 astral Unicode characters', '🧵'.repeat(150), '🧵'.repeat(150)],
    ['counts combining marks as separate Unicode characters', 'і\u0308'.repeat(75), 'і\u0308'.repeat(75)]
];

for (const [label, input, expected] of validNames) {
    test(`CategoryManager requiredName ${label}`, () => {
        const result = phpJson(`
            ${dependencies}
            $method = new ReflectionMethod(CategoryManager::class, 'requiredName');
            $name = $method->invoke(null, ${phpInput(input)});
            echo json_encode([
                'name' => $name,
                'databaseCalls' => Database::$calls
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        `);

        assert.deepEqual(result, {name: expected, databaseCalls: 0});
        if (label.includes('astral')) {
            assert.equal([...result.name].length, 150);
            // Native HTML maxlength counts UTF-16 units: the existing 150
            // limit can be stricter than PHP for astral characters.
            assert.equal(result.name.length, 300);
        }
    });
}

const controllerInputs = invalidInputs.filter(([label]) => [
    'empty name', 'ASCII whitespace name', '151 Ukrainian characters'
].includes(label));

for (const operation of ['create', 'update']) {
    for (const [label, input, message] of controllerInputs) {
        test(`AdminCategoryController.${operation} returns the existing 422 envelope for ${label}`, () => {
            // jsonError exits; a shutdown observer captures the actual emitted
            // JSON and response code without replacing any controller method.
            const result = phpJson(`
                ${dependencies}
                class Controller {}
                class Language {
                    public static $calls = 0;
                    public static function active() {
                        self::$calls++;
                        return [];
                    }
                }
                require 'app/Controllers/AdminCategoryController.php';
                $_POST = ${phpInput(input)};
                ob_start();
                register_shutdown_function(static function () {
                    $response = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
                    echo json_encode([
                        'status' => http_response_code(),
                        'response' => $response,
                        'databaseCalls' => Database::$calls,
                        'languageCalls' => Language::$calls,
                        'translationCalls' => CategoryTranslator::$calls
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                });
                $controller = (new ReflectionClass(AdminCategoryController::class))
                    ->newInstanceWithoutConstructor();
                $controller->${operation}();
            `);

            assert.deepEqual(result, {
                status: 422,
                response: {success: false, message},
                databaseCalls: 0,
                // update retains its existing preflight lookups, stubbed above.
                languageCalls: operation === 'update' ? 1 : 0,
                translationCalls: operation === 'update' ? 1 : 0
            });
        });
    }
}
