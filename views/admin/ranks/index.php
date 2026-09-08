<?php
$ranks = is_array($ranks ?? null) ? $ranks : [];
$summary = is_array($summary ?? null) ? $summary : [];
$defaultRank = is_array($defaultRank ?? null) ? $defaultRank : null;
$languages = is_array($languages ?? null) ? $languages : [];
$translationStatusOptions = is_array($translationStatusOptions ?? null)
    ? $translationStatusOptions
    : TranslationWorkflow::statusOptions();
$escape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$orderableRankIds = [];
foreach ($ranks as $rankItem) {
    if (($rankItem['slug'] ?? '') === 'guest') {
        continue;
    }
    $orderableRankIds[] = (int) ($rankItem['id'] ?? 0);
}

$firstOrderableId = $orderableRankIds[0] ?? 0;
$lastOrderableId = !empty($orderableRankIds)
    ? $orderableRankIds[count($orderableRankIds) - 1]
    : 0;
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle ?? 'Адмін-панель · Ранги') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/admin-ranks.css?v=6">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-ranks-page">
    <?php if (!empty($message)): ?>
        <div class="admin-rank-message"><?= $escape($message) ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="admin-rank-message is-error"><?= $escape($error) ?></div>
    <?php endif; ?>

    <section class="admin-ranks-summary" aria-label="Статистика рангів">
        <div class="admin-rank-stat">
            <strong><?= (int) ($summary['total'] ?? 0) ?></strong>
            <span>Усього</span>
        </div>
        <div class="admin-rank-stat">
            <strong><?= (int) ($summary['active'] ?? 0) ?></strong>
            <span>Активних</span>
        </div>
        <div class="admin-rank-stat">
            <strong><?= (int) ($summary['inactive'] ?? 0) ?></strong>
            <span>Вимкнених</span>
        </div>
    </section>

    <section class="admin-rank-default">
        <strong>Ранг для нової реєстрації</strong>
        <span>
            <?= $defaultRank
                ? $escape($defaultRank['name'] ?? '')
                : 'Не визначено' ?>
        </span>
    </section>

    <form
        class="admin-rank-create"
        method="post"
        action="/Anabelka/admin/ranks/create"
        autocomplete="off"
    >
        <div class="admin-rank-create-field">
            <input
                type="text"
                name="rank_name"
                maxlength="100"
                placeholder="Назва нового рангу українською"
                autocomplete="off"
                autocorrect="off"
                spellcheck="false"
                required
            >
            <small>Українська — вихідна мова. Після створення переклади можна додати вручну або через ШІ.</small>
        </div>
        <button class="admin-rank-button is-primary" type="submit">
            Створити ранг
        </button>
    </form>

    <section class="admin-rank-list">
        <?php foreach ($ranks as $rank): ?>
            <?php
            $rankId = (int) ($rank['id'] ?? 0);
            $isActive = !empty($rank['is_active']);
            $isGuest = ($rank['slug'] ?? '') === 'guest';
            $isDefault = $defaultRank
                && (int) ($defaultRank['id'] ?? 0) === $rankId;
            $canMoveUp = !$isGuest && $rankId !== $firstOrderableId;
            $canMoveDown = !$isGuest && $rankId !== $lastOrderableId;
            $translations = is_array($rank['translations'] ?? null)
                ? $rank['translations']
                : [];
            ?>
            <article class="admin-rank-card">
                <div class="admin-rank-card-head">
                    <div>
                        <strong><?= $escape($rank['name'] ?? '') ?></strong>
                        <div class="admin-rank-meta">
                            <span>slug: <?= $escape($rank['slug'] ?? '') ?></span>
                            <span>Користувачів: <?= (int) ($rank['user_count'] ?? 0) ?></span>
                            <span>Товарів із ціною: <?= (int) ($rank['priced_product_count'] ?? 0) ?></span>
                            <?php if ($isGuest): ?>
                                <span>Системний ранг</span>
                            <?php endif; ?>
                            <?php if ($isDefault): ?>
                                <span>За замовчуванням для реєстрації</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <span class="admin-rank-status <?= $isActive ? 'is-active' : 'is-inactive' ?>">
                        <?= $isActive ? 'Активний' : 'Вимкнений' ?>
                    </span>
                </div>

                <form class="admin-rank-edit" method="post" action="/Anabelka/admin/ranks/update">
                    <input type="hidden" name="rank_id" value="<?= $rankId ?>">

                    <label class="admin-rank-source-field">
                        <span>Назва українською</span>
                        <input
                            type="text"
                            name="name"
                            class="admin-rank-source-name"
                            maxlength="100"
                            value="<?= $escape($rank['name'] ?? '') ?>"
                            required
                        >
                    </label>

                    <?php if (!empty($languages)): ?>
                        <details class="admin-rank-translations">
                            <summary>
                                <span>Переклади назви</span>
                                <small>Вручну або через ШІ</small>
                            </summary>

                            <div class="admin-rank-translation-list">
                                <?php foreach ($languages as $language): ?>
                                    <?php
                                    $code = strtolower(trim((string) ($language['code'] ?? '')));
                                    if ($code === '' || $code === Language::SOURCE_CODE) {
                                        continue;
                                    }
                                    $translation = is_array($translations[$code] ?? null)
                                        ? $translations[$code]
                                        : [];
                                    $translationName = (string) ($translation['name'] ?? '');
                                    $translationSource = TranslationWorkflow::normalizeSource(
                                        $translation['source'] ?? 'manual'
                                    );
                                    $translationStatus = TranslationWorkflow::normalizeStatus(
                                        $translation['status'] ?? 'draft',
                                        trim($translationName) !== '',
                                        TranslationWorkflow::STATUS_DRAFT
                                    );
                                    ?>
                                    <section
                                        class="admin-rank-translation"
                                        data-rank-translation
                                        data-language="<?= $escape($code) ?>"
                                    >
                                        <div class="admin-rank-translation-head">
                                            <strong>
                                                <?= $escape($language['name'] ?? $code) ?>
                                                · <?= $escape($language['short_name'] ?? strtoupper($code)) ?>
                                            </strong>
                                            <button
                                                type="button"
                                                class="admin-rank-ai-button"
                                                data-rank-ai-translate
                                                data-target-language="<?= $escape($code) ?>"
                                            >Перекласти через ШІ</button>
                                        </div>

                                        <input
                                            type="text"
                                            name="translation_name[<?= $escape($code) ?>]"
                                            value="<?= $escape($translationName) ?>"
                                            maxlength="100"
                                            placeholder="Назва рангу"
                                            autocomplete="off"
                                            data-rank-translation-name
                                        >

                                        <div class="admin-rank-translation-workflow">
                                            <input
                                                type="hidden"
                                                name="translation_source[<?= $escape($code) ?>]"
                                                value="<?= $escape($translationSource) ?>"
                                                data-rank-translation-source
                                            >
                                            <span data-rank-translation-origin>
                                                <?= $escape(TranslationWorkflow::sourceLabel($translationSource)) ?>
                                            </span>
                                            <label>
                                                <span>Стан</span>
                                                <select
                                                    name="translation_status[<?= $escape($code) ?>]"
                                                    data-rank-translation-status
                                                >
                                                    <?php foreach ($translationStatusOptions as $statusCode => $statusLabel): ?>
                                                        <option
                                                            value="<?= $escape($statusCode) ?>"
                                                            <?= $translationStatus === $statusCode ? 'selected' : '' ?>
                                                        ><?= $escape($statusLabel) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                        </div>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>

                    <button class="admin-rank-button admin-rank-save" type="submit">
                        Зберегти
                    </button>
                </form>

                <div class="admin-rank-actions">
                    <?php if (!$isGuest): ?>
                        <div class="admin-rank-order-actions" aria-label="Змінити порядок рангу">
                            <form method="post" action="/Anabelka/admin/ranks/move">
                                <input type="hidden" name="rank_id" value="<?= $rankId ?>">
                                <input type="hidden" name="direction" value="up">
                                <button
                                    class="admin-rank-order-button"
                                    type="submit"
                                    aria-label="Перемістити ранг вище"
                                    title="Перемістити вище"
                                    <?= $canMoveUp ? '' : 'disabled' ?>
                                >↑</button>
                            </form>
                            <form method="post" action="/Anabelka/admin/ranks/move">
                                <input type="hidden" name="rank_id" value="<?= $rankId ?>">
                                <input type="hidden" name="direction" value="down">
                                <button
                                    class="admin-rank-order-button"
                                    type="submit"
                                    aria-label="Перемістити ранг нижче"
                                    title="Перемістити нижче"
                                    <?= $canMoveDown ? '' : 'disabled' ?>
                                >↓</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if ($isActive && !$isGuest && !$isDefault): ?>
                        <form method="post" action="/Anabelka/admin/ranks/default">
                            <input type="hidden" name="rank_id" value="<?= $rankId ?>">
                            <button class="admin-rank-button" type="submit">
                                Зробити рангом нових користувачів
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if (!$isGuest): ?>
                        <form method="post" action="/Anabelka/admin/ranks/toggle">
                            <input type="hidden" name="rank_id" value="<?= $rankId ?>">
                            <button
                                class="admin-rank-button <?= $isActive ? 'is-danger' : '' ?>"
                                type="submit"
                            >
                                <?= $isActive ? 'Вимкнути' : 'Увімкнути' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</main>

<script src="/Anabelka/js/admin-rank-translations.js?v=1"></script>
</body>
</html>
