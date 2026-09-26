<details
    class="home-useful-menu"
    data-home-block="<?= $escape($homeBlockKey) ?>"
>
    <summary>
        <?= $escape(
            Translator::t('home.useful_title', 'Корисне')
        ) ?>
    </summary>
    <nav
        aria-label="<?= $escape(
            Translator::t('home.useful_title', 'Корисне')
        ) ?>"
    >
        <a href="/Anabelka/news">
            <?= $escape(
                Translator::t('home.utility_news', 'Новини')
            ) ?>
        </a>
        <a href="/Anabelka/reviews">
            <?= $escape(
                Translator::t(
                    'home.utility_reviews',
                    'Відгуки покупців'
                )
            ) ?>
        </a>
        <a href="/Anabelka/gift-certificates">
            <?= $escape(
                Translator::t(
                    'home.utility_gifts',
                    'Подарункові сертифікати'
                )
            ) ?>
        </a>
    </nav>
</details>
