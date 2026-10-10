<?php
$pagination = is_array($pagination ?? null) ? $pagination : [];
$paginationPath = trim((string) ($paginationPath ?? ''));
$paginationQuery = is_array($paginationQuery ?? null)
    ? $paginationQuery
    : [];
$paginationLabel = trim((string) (
    $paginationLabel
    ?? Translator::t('public.pagination.label', 'Посторінкова навігація')
));

$currentPage = max(1, (int) ($pagination['page'] ?? 1));
$totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));

if ($totalPages <= 1 || $paginationPath === '') {
    return;
}

$paginationEscape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$paginationUrl = static function ($pageNumber) use (
    $paginationPath,
    $paginationQuery
) {
    $query = $paginationQuery;
    $pageNumber = max(1, (int) $pageNumber);

    if ($pageNumber > 1) {
        $query['page'] = $pageNumber;
    } else {
        unset($query['page']);
    }

    $queryString = http_build_query($query);

    return $paginationPath
        . ($queryString !== '' ? '?' . $queryString : '');
};

$visiblePages = [1, $totalPages];

for (
    $pageNumber = max(1, $currentPage - 2);
    $pageNumber <= min($totalPages, $currentPage + 2);
    $pageNumber++
) {
    $visiblePages[] = $pageNumber;
}

$visiblePages = array_values(array_unique($visiblePages));
sort($visiblePages);
$previousVisiblePage = null;
?>
<nav
    class="anabelka-pagination"
    aria-label="<?= $paginationEscape($paginationLabel) ?>"
>
    <?php if (!empty($pagination['has_previous'])): ?>
        <a
            class="anabelka-pagination-link is-direction"
            href="<?= $paginationEscape($paginationUrl($currentPage - 1)) ?>"
            rel="prev"
        >← <?= $paginationEscape(
            Translator::t('public.pagination.previous', 'Назад')
        ) ?></a>
    <?php endif; ?>

    <?php foreach ($visiblePages as $pageNumber): ?>
        <?php if (
            $previousVisiblePage !== null
            && $pageNumber - $previousVisiblePage > 1
        ): ?>
            <span
                class="anabelka-pagination-ellipsis"
                aria-hidden="true"
            >…</span>
        <?php endif; ?>

        <?php if ($pageNumber === $currentPage): ?>
            <span
                class="anabelka-pagination-link is-current"
                aria-current="page"
            ><?= $pageNumber ?></span>
        <?php else: ?>
            <a
                class="anabelka-pagination-link"
                href="<?= $paginationEscape($paginationUrl($pageNumber)) ?>"
            ><?= $pageNumber ?></a>
        <?php endif; ?>

        <?php $previousVisiblePage = $pageNumber; ?>
    <?php endforeach; ?>

    <?php if (!empty($pagination['has_next'])): ?>
        <a
            class="anabelka-pagination-link is-direction"
            href="<?= $paginationEscape($paginationUrl($currentPage + 1)) ?>"
            rel="next"
        ><?= $paginationEscape(
            Translator::t('public.pagination.next', 'Далі')
        ) ?> →</a>
    <?php endif; ?>
</nav>
