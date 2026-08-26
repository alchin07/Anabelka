<div class="delivery-card">

    <!--
     * Карточка способа доставки.
     -->
    <div
        class="admin-tree-row no-add"
        style="--level: 1;"
    >

        <div
            class="
                admin-tree-item
                delivery-row
                method-row
            "
        >

            <div class="admin-tree-main">

                <div class="admin-tree-controls">

                    <button
                        type="button"
                        class="admin-tree-collapse"
                        data-collapse-method="<?= (int) $method['id'] ?>"
                        aria-label="Свернуть службы доставки"
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
                        data-move-type="method"
                        data-move-id="<?= (int) $method['id'] ?>"
                        aria-label="Переместить способ доставки"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <circle
                                cx="8"
                                cy="6"
                                r="1.5"
                            ></circle>

                            <circle
                                cx="16"
                                cy="6"
                                r="1.5"
                            ></circle>

                            <circle
                                cx="8"
                                cy="12"
                                r="1.5"
                            ></circle>

                            <circle
                                cx="16"
                                cy="12"
                                r="1.5"
                            ></circle>

                            <circle
                                cx="8"
                                cy="18"
                                r="1.5"
                            ></circle>

                            <circle
                                cx="16"
                                cy="18"
                                r="1.5"
                            ></circle>
                        </svg>
                    </button>

                </div>


                <div class="admin-tree-text">

                    <span
                        class="delivery-name"
                    >
                        <?= htmlspecialchars(
                            $method['name']
                        ) ?>
                    </span>


                    <?php if (
                        !empty(
                            $method['description']
                        )
                    ): ?>

                        <span
                            class="delivery-description"
                        >
                            <?= htmlspecialchars(
                                $method['description']
                            ) ?>
                        </span>

                    <?php endif; ?>

                </div>

            </div>


            <?php

            $isActive =
                $method['is_active'];

            require __DIR__
                . '/status-icon.php';

            ?>


            <?php

            $actionType =
                'method';

            $itemId =
                $method['id'];

            $isActive =
                $method['is_active'];

            $itemName =
                $method['name'];

            $itemDescription =
                $method['description']
                ?? '';

            $itemSortOrder =
                $method['sort_order']
                ?? 0;

            require __DIR__
                . '/action-buttons.php';

            ?>

        </div>

    </div>


    <!--
     * Службы этого способа доставки.
     -->
    <div
        class="admin-tree-children"
        data-method-children="<?= (int) $method['id'] ?>"
    >
        <?php foreach (
    $method['services'] ?? []
    as $serviceIndex => $service
): ?>

    <?php

    $isFirstService =
        $serviceIndex === 0;

    require __DIR__
        . '/service-row.php';

    ?>

<?php endforeach; ?>    

    </div>

</div>