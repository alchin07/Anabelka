<div class="delivery-service">

    <div
        class="admin-tree-row<?= $isFirstService ? '' : ' no-add' ?>"
        style="--level: 2;"
    >

        <?php if ($isFirstService): ?>

            <button
                type="button"
                class="admin-tree-add add-delivery-service"
                data-method-id="<?= (int) $method['id'] ?>"
                data-method-name="<?= htmlspecialchars(
                    $method['name'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                aria-label="Добавить службу"
            >
                +
            </button>

        <?php endif; ?>

        <div
            class="
                admin-tree-item
                delivery-row
                service-row
            "
        >

            <div class="admin-tree-main">

                <div class="admin-tree-controls">

                    <button
                        type="button"
                        class="admin-tree-collapse"
                        data-collapse-service="<?= (int) $service['id'] ?>"
                        aria-label="Свернуть опции доставки"
                        aria-expanded="true"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <polyline
                                points="6 9 12 15 18 9"
                            ></polyline>
                        </svg>
                    </button>

                    <button
                        type="button"
                        class="admin-tree-move"
                        data-move-type="service"
                        data-move-id="<?= (int) $service['id'] ?>"
                        aria-label="Переместить службу доставки"
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
                            $service['name']
                        ) ?>
                    </span>

                    <?php if (!empty($service['description'])): ?>

                        <span class="delivery-description">
                            <?= htmlspecialchars(
                                $service['description']
                            ) ?>
                        </span>

                    <?php endif; ?>

                </div>

            </div>

            <?php

            $isActive =
                $service['is_active'];

            require __DIR__
                . '/status-icon.php';

            $actionType =
                'service';

            $itemId =
                $service['id'];

            $isActive =
                $service['is_active'];

            $itemName =
                $service['name'];

            $itemDescription =
                $service['description']
                ?? '';

            $itemSortOrder =
                $service['sort_order']
                ?? 0;

            require __DIR__
                . '/action-buttons.php';

            ?>

        </div>

    </div>

    <div
        class="admin-tree-children"
        data-service-children="<?= (int) $service['id'] ?>"
    >

        <?php

        $options =
            $service['options']
            ?? [];

        ?>

        <?php if (empty($options)): ?>

            <div
                class="admin-tree-row"
                style="--level: 3;"
            >
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

                <div
                    class="
                        admin-tree-item
                        delivery-row
                        option-row
                        admin-tree-empty
                    "
                >
                    <div class="admin-tree-main">
                        <div class="admin-tree-text">
                            <span class="delivery-name">
                                Создать опцию
                            </span>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>

            <?php

            $isFirstOption = true;

            foreach ($options as $option):

                require __DIR__
                    . '/option-row.php';

                $isFirstOption = false;

            endforeach;

            ?>

        <?php endif; ?>

    </div>

</div>