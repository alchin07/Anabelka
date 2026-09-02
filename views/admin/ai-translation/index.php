<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>ШІ-переклад — Адмін-панель</title>

    <link
        rel="stylesheet"
        href="/Anabelka/css/style.css?v=8"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/catalog.css?v=4"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/admin-ai-dashboard.css?v=1"
    >
</head>
<body>

<?php
$pageTitle = 'Адмін-панель — ШІ-переклад';
require __DIR__ . '/../../partials/header.php';

$periods = is_array($statistics['periods'] ?? null)
    ? $statistics['periods']
    : [];

$today = is_array($periods['today'] ?? null)
    ? $periods['today']
    : [];

$month = is_array($periods['month'] ?? null)
    ? $periods['month']
    : [];

$total = is_array($periods['total'] ?? null)
    ? $periods['total']
    : [];

$providerStatistics = is_array($statistics['providers'] ?? null)
    ? $statistics['providers']
    : [];

$recent = is_array($statistics['recent'] ?? null)
    ? $statistics['recent']
    : [];

$hasConfiguredProvider = false;

foreach ($providers ?? [] as $provider) {
    if (!empty($provider['configured'])) {
        $hasConfiguredProvider = true;
        break;
    }
}

$formatNumber = function ($value) {
    return number_format((int) $value, 0, ',', ' ');
};

$providerName = function ($code) use ($providers) {
    return (string) (
        $providers[$code]['name']
        ?? $code
        ?? '—'
    );
};

$contextName = function ($context) {
    $context = strtolower(trim((string) $context));

    if (strpos($context, 'interface') === 0) {
        return 'Інтерфейс';
    }

    $labels = [
        'catalog' => 'Каталог',
        'category' => 'Категорії',
        'product' => 'Товари',
        'delivery' => 'Доставка',
        'delivery_method' => 'Спосіб доставки',
        'delivery_service' => 'Служба доставки',
        'delivery_option' => 'Опція доставки',
        'delivery_option_input' => 'Поле покупця'
    ];

    return $labels[$context] ?? ($context ?: 'Переклад');
};
?>

<main class="catalog">
    <section class="product-card ai-settings-admin">

        <div class="ai-settings-head">
            <div>
                <h2>ШІ-переклад</h2>
                <p>
                    Основний сервіс, стан підключення та статистика
                </p>
            </div>

            <a href="/Anabelka/admin/translations">
                До центру перекладів
            </a>
        </div>

        <?php if (!empty($saved)): ?>
            <div class="ai-settings-message is-success">
                ШІ за замовчуванням збережено.
            </div>
        <?php endif; ?>

        <?php if (!empty($settingsError)): ?>
            <div class="ai-settings-message is-error">
                <?= htmlspecialchars((string) $settingsError) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($statisticsError)): ?>
            <div class="ai-settings-message is-error">
                Статистика тимчасово недоступна:
                <?= htmlspecialchars((string) $statisticsError) ?>
            </div>
        <?php endif; ?>

        <section class="ai-settings-section">
            <h3>ШІ за замовчуванням</h3>

            <p class="ai-settings-help">
                Він автоматично вибиратиметься на початку нового сеансу.
                Верхній список в адмін-панелі тимчасово змінює сервіс лише
                для поточної роботи.
            </p>

            <form
                class="ai-default-form"
                method="post"
                action="/Anabelka/admin/ai-translation/default-provider"
            >
                <label for="default-ai-provider">
                    Основний сервіс
                </label>

                <div class="ai-default-controls">
                    <select
                        id="default-ai-provider"
                        name="provider"
                        <?= $hasConfiguredProvider ? '' : 'disabled' ?>
                    >
                        <?php foreach ($providers ?? [] as $code => $provider): ?>
                            <?php $configured = !empty($provider['configured']); ?>

                            <option
                                value="<?= htmlspecialchars((string) $code) ?>"
                                <?= (string) $defaultProvider === (string) $code
                                    ? 'selected'
                                    : '' ?>
                                <?= $configured ? '' : 'disabled' ?>
                            >
                                <?= htmlspecialchars(
                                    (string) ($provider['name'] ?? $code)
                                ) ?><?= $configured ? '' : ' — немає ключа' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button
                        type="submit"
                        <?= $hasConfiguredProvider ? '' : 'disabled' ?>
                    >
                        Зберегти
                    </button>
                </div>
            </form>
        </section>

        <section class="ai-settings-section">
            <h3>Стан сервісів</h3>

            <div class="ai-provider-grid">
                <?php foreach ($providers ?? [] as $code => $provider): ?>
                    <?php
                    $configured = !empty($provider['configured']);
                    $isDefault = (string) $defaultProvider === (string) $code;
                    ?>

                    <article class="ai-provider-card<?= $isDefault ? ' is-default' : '' ?>">
                        <div class="ai-provider-card-head">
                            <strong>
                                <?= htmlspecialchars(
                                    (string) ($provider['name'] ?? $code)
                                ) ?>
                            </strong>

                            <span class="ai-provider-state<?= $configured ? ' is-ready' : '' ?>">
                                <?= $configured ? 'Підключено' : 'Потрібен ключ' ?>
                            </span>
                        </div>

                        <span class="ai-provider-role">
                            <?= $isDefault
                                ? 'Використовується за замовчуванням'
                                : ($configured
                                    ? 'Доступний для вибору'
                                    : 'Недоступний') ?>
                        </span>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="ai-settings-section">
            <div class="ai-section-heading">
                <h3>Використання</h3>
                <span>облік ведеться з цього оновлення</span>
            </div>

            <div class="ai-period-grid">
                <article class="ai-period-card">
                    <span>Сьогодні</span>
                    <strong>
                        <?= $formatNumber($today['characters'] ?? 0) ?>
                    </strong>
                    <small>
                        символів ·
                        <?= $formatNumber($today['requests'] ?? 0) ?> звернень
                    </small>
                </article>

                <article class="ai-period-card">
                    <span>Цього місяця</span>
                    <strong>
                        <?= $formatNumber($month['characters'] ?? 0) ?>
                    </strong>
                    <small>
                        символів ·
                        <?= $formatNumber($month['requests'] ?? 0) ?> звернень
                    </small>
                </article>

                <article class="ai-period-card">
                    <span>За весь час</span>
                    <strong>
                        <?= $formatNumber($total['characters'] ?? 0) ?>
                    </strong>
                    <small>
                        символів ·
                        <?= $formatNumber($total['requests'] ?? 0) ?> звернень
                    </small>
                </article>
            </div>

            <div class="ai-total-details">
                <span>
                    Успішно:
                    <strong><?= $formatNumber($total['success'] ?? 0) ?></strong>
                </span>
                <span>
                    Помилки:
                    <strong><?= $formatNumber($total['errors'] ?? 0) ?></strong>
                </span>
                <span>
                    Вхідні символи:
                    <strong><?= $formatNumber($total['input_characters'] ?? 0) ?></strong>
                </span>
                <span>
                    Вихідні символи:
                    <strong><?= $formatNumber($total['output_characters'] ?? 0) ?></strong>
                </span>
            </div>

            <p class="ai-statistics-note">
                Це внутрішній лічильник символів у вихідному тексті та
                відповіді. Він не замінює платіжну статистику провайдера,
                де розрахунок може вестися в токенах або лише за вхідними
                символами.
            </p>
        </section>

        <?php if (!empty($providerStatistics)): ?>
            <section class="ai-settings-section">
                <h3>За сервісами</h3>

                <div class="ai-provider-stat-list">
                    <?php foreach ($providerStatistics as $item): ?>
                        <?php $code = (string) ($item['provider_code'] ?? ''); ?>

                        <article class="ai-provider-stat-card">
                            <strong>
                                <?= htmlspecialchars($providerName($code)) ?>
                            </strong>

                            <div>
                                <span>
                                    Звернень
                                    <b><?= $formatNumber($item['requests'] ?? 0) ?></b>
                                </span>
                                <span>
                                    Символів
                                    <b><?= $formatNumber($item['characters'] ?? 0) ?></b>
                                </span>
                                <span>
                                    Помилок
                                    <b><?= $formatNumber($item['errors'] ?? 0) ?></b>
                                </span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="ai-settings-section">
            <h3>Останні звернення</h3>

            <?php if (empty($recent)): ?>
                <div class="ai-statistics-empty">
                    Статистика з’явиться після першого ШІ-перекладу.
                </div>
            <?php else: ?>
                <div class="ai-recent-list">
                    <?php foreach ($recent as $item): ?>
                        <?php
                        $input = (int) ($item['input_characters'] ?? 0);
                        $output = (int) ($item['output_characters'] ?? 0);
                        $date = strtotime((string) ($item['created_at'] ?? ''));
                        ?>

                        <article class="ai-recent-card">
                            <div>
                                <strong>
                                    <?= htmlspecialchars(
                                        $providerName(
                                            (string) ($item['provider_code'] ?? '')
                                        )
                                    ) ?>
                                </strong>
                                <span>
                                    <?= htmlspecialchars(
                                        $contextName(
                                            (string) ($item['translation_context'] ?? '')
                                        )
                                    ) ?>
                                    · <?= htmlspecialchars(
                                        strtoupper(
                                            (string) ($item['target_language'] ?? '')
                                        )
                                    ) ?>
                                </span>
                            </div>

                            <div class="ai-recent-result">
                                <span class="<?= !empty($item['is_success']) ? 'is-success' : 'is-error' ?>">
                                    <?= !empty($item['is_success'])
                                        ? 'Успішно'
                                        : 'Помилка' ?>
                                </span>
                                <small>
                                    <?= $formatNumber($input + $output) ?> симв.
                                    · <?= $date ? date('d.m.Y H:i', $date) : '—' ?>
                                </small>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </section>
</main>

</body>
</html>
