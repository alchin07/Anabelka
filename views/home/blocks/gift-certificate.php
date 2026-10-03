<section
    class="home-rail-card home-rail-gift"
    data-home-block="<?= $railEscape($homeBlockKey) ?>"
>
    <div class="home-rail-gift-mark" aria-hidden="true">A</div>
    <h2><?= $railEscape(Translator::t('home.gift_title', 'Подарунковий сертифікат')) ?></h2>
    <p><?= $railEscape(Translator::t('home.gift_text', 'Готуємо електронний сертифікат Анабельки для подарунка іншій людині.')) ?></p>
    <a href="/Anabelka/gift-certificates">
        <?= $railEscape(Translator::t('home.gift_more', 'Дізнатися більше')) ?> →
    </a>
</section>
