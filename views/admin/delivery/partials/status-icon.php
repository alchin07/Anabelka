<?php

$isActive =
    !empty(
        $isActive
    );

?>

<div
    class="
        row-status
        <?= $isActive
            ? 'active'
            : 'inactive' ?>
    "
    title="<?= $isActive
        ? 'Включено'
        : 'Выключено' ?>"
>
    <?= $isActive
        ? '✓'
        : '×' ?>
</div>  