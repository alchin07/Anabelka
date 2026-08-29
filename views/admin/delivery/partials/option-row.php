<div
    class="admin-tree-row<?= $isFirstOption ? '' : ' no-add' ?>"
    style="--level: 3;"
>

    <?php if ($isFirstOption): ?>

        <button
            type="button"
            class="admin-tree-add add-delivery-option"
            data-service-id="<?= (int) $service['id'] ?>"
            data-service-name="<?= htmlspecialchars(
                $service['name'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
            aria-label="Добавить опцию"
        >
            +
        </button>

    <?php endif; ?>

    <div
        class="
            admin-tree-item
            delivery-row
            option-row
        "
    >

        <div class="admin-tree-main">

            <div class="admin-tree-controls">

                <span
                    class="admin-tree-control-placeholder"
                    aria-hidden="true"
                ></span>

                <button
                    type="button"
                    class="admin-tree-move"
                    data-move-type="option"
                    data-move-id="<?= (int) $option['id'] ?>"
                    aria-label="Переместить опцию доставки"
                >
                    <svg
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <circle cx="8" cy="6" r="1.5"></circle>
                        <circle cx="16" cy="6" r="1.5"></circle>
                        <circle cx="8" cy="12" r="1.5"></circle>
                        <circle cx="16" cy="12" r="1.5"></circle>
                        <circle cx="8" cy="18" r="1.5"></circle>
                        <circle cx="16" cy="18" r="1.5"></circle>
                    </svg>
                </button>

            </div>

            <div class="admin-tree-text">

                <span class="delivery-name">
                    <?= htmlspecialchars(
                        $option['name']
                    ) ?>
                </span>

                <?php if (!empty($option['description'])): ?>

                    <span class="delivery-description">
                        <?= htmlspecialchars(
                            $option['description']
                        ) ?>
                    </span>

                <?php endif; ?>

            </div>

        </div>

        <?php

        $isActive =
            $option['is_active'];

        require __DIR__
            . '/status-icon.php';

        $actionType =
            'option';

        $itemId =
            $option['id'];

        $isActive =
            $option['is_active'];

        $itemName =
            $option['name'];

        $itemDescription =
            $option['description']
            ?? '';

        $itemSortOrder =
            $option['sort_order']
            ?? 0;

        require __DIR__
            . '/action-buttons.php';

        ?>

    </div>

</div>