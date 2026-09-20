<?php if (!empty($adultDirections)): ?>
    <section
        class="home-adult-section"
        aria-label="18+"
        data-home-block="<?= $escape($homeBlockKey) ?>"
    >
        <div class="home-adult-copy">
            <span class="home-adult-badge">18+</span>

            <div>
                <h2>
                    <?= $escape(
                        Translator::t(
                            'home.adult_entry_title',
                            'Інтимні товари'
                        )
                    ) ?>
                </h2>

                <p>
                    <?= $escape(
                        Translator::t(
                            'home.adult_entry_text',
                            'Окремий приватний розділ для повнолітніх.'
                        )
                    ) ?>
                </p>
            </div>
        </div>

        <div class="home-adult-links">
            <?php foreach ($adultDirections as $direction): ?>
                <a
                    href="<?= $escape(
                        AdultAccess::gateUrl($direction)
                    ) ?>"
                >
                    <?= $escape(
                        Translator::t(
                            'home.adult_open',
                            'Перейти до розділу 18+'
                        )
                    ) ?> →
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
