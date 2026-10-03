<section
    class="home-section"
    data-home-block="<?= $escape($homeBlockKey) ?>"
>
    <div class="home-section-head">
        <div class="home-section-title">
            <h2>
                <?= $escape(
                    Translator::t(
                        'home.directions_title',
                        'Напрямки магазину'
                    )
                ) ?>
            </h2>
            <p>
                <?= $escape(
                    Translator::t(
                        'home.directions_text',
                        'Оберіть потрібний розділ і переходьте до категорій та товарів.'
                    )
                ) ?>
            </p>
        </div>

        <a class="home-section-link" href="/Anabelka/catalog">
            <?= $escape(
                Translator::t('home.all_catalog', 'Увесь каталог')
            ) ?> →
        </a>
    </div>

    <?php if (empty($standardDirections)): ?>
        <div class="home-empty">
            <?= $escape(
                Translator::t(
                    'home.empty_directions',
                    'Напрямки магазину ще не налаштовані.'
                )
            ) ?>
        </div>
    <?php else: ?>
        <div class="home-direction-grid">
            <?php foreach ($standardDirections as $direction): ?>
                <?php
                $directionImage = $assetUrl($direction['image'] ?? '');
                $directionName = trim((string) ($direction['name'] ?? ''));
                $directionLetter = function_exists('mb_substr')
                    ? mb_substr($directionName, 0, 1, 'UTF-8')
                    : substr($directionName, 0, 1);
                ?>

                <a
                    class="home-direction-card<?= $directionImage === '' ? ' no-image' : '' ?>"
                    href="<?= $escape(Category::catalogUrl($direction)) ?>"
                >
                    <div class="home-direction-media">
                        <?php if ($directionImage !== ''): ?>
                            <img
                                src="<?= $escape($directionImage) ?>"
                                alt="<?= $escape($directionName) ?>"
                                loading="lazy"
                            >
                        <?php else: ?>
                            <div class="home-direction-placeholder">
                                <?= $escape($directionLetter) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="home-direction-overlay">
                        <strong><?= $escape($directionName) ?></strong>
                        <span>
                            <?= $escape(
                                Translator::t(
                                    'home.direction_open',
                                    'Відкрити розділ'
                                )
                            ) ?> →
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
