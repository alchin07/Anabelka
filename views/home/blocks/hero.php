<section
    class="home-hero"
    data-home-block="<?= $escape($homeBlockKey) ?>"
>
    <div class="home-hero-content">
        <div class="home-eyebrow">
            <?= $escape(
                Translator::t('home.hero_eyebrow', 'Анабелька')
            ) ?>
        </div>

        <h1>
            <?= $escape(
                Translator::t(
                    'home.hero_title',
                    'Для щоденного комфорту, дому, відпочинку та особливих моментів'
                )
            ) ?>
        </h1>

        <p class="home-hero-text">
            <?= $escape(
                Translator::t(
                    'home.hero_text',
                    'Білизна, панчішно-шкарпеткові вироби, домашній одяг, купальники та інші напрямки в одному магазині.'
                )
            ) ?>
        </p>

        <div class="home-hero-actions">
            <a
                class="home-button is-primary"
                href="/Anabelka/catalog"
            >
                <?= $escape(
                    Translator::t(
                        'home.hero_catalog',
                        'Перейти до каталогу'
                    )
                ) ?>
            </a>
        </div>
    </div>

    <div class="home-hero-mark" aria-hidden="true">
        <div class="home-hero-mark-inner">A</div>
    </div>
</section>
