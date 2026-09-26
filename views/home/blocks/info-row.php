<section
    class="home-info-row"
    aria-label="Інформація магазину"
    data-home-block="<?= $escape($homeBlockKey) ?>"
>
    <div class="home-info-item">
        <?= $escape(
            Translator::t('home.footer_delivery', 'Доставка')
        ) ?>
    </div>
    <div class="home-info-item">
        <?= $escape(
            Translator::t('home.footer_payment', 'Оплата')
        ) ?>
    </div>
    <div class="home-info-item">
        <?= $escape(
            Translator::t('home.footer_returns', 'Повернення')
        ) ?>
    </div>
    <div class="home-info-item">
        <?= $escape(
            Translator::t('home.footer_contacts', 'Контакти')
        ) ?>
    </div>
</section>
