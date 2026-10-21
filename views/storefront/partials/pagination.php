<?php
$collection = is_array($collection ?? null) ? $collection : [];
$collectionPath = trim((string) ($collectionPath ?? ''));
$paginationEscape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$currentPage = max(1, (int) ($collection['page'] ?? 1));
$totalPages = max(1, (int) ($collection['total_pages'] ?? 1));
$windowStart = max(1, $currentPage - 2);
$windowEnd = min($totalPages, $currentPage + 2);
?>
<?php if ($totalPages > 1 && $collectionPath !== ''): ?>
    <nav class="storefront-pagination" aria-label="Пагінація">
        <?php if (!empty($collection['has_previous'])): ?>
            <a
                class="storefront-pagination-link"
                href="<?= $paginationEscape($collectionPath . '?page=' . ($currentPage - 1)) ?>"
            >
                <?= $paginationEscape(
                    Translator::t('storefront.pagination.previous', 'Назад')
                ) ?>
            </a>
        <?php endif; ?>

        <?php for ($pageNumber = $windowStart; $pageNumber <= $windowEnd; $pageNumber++): ?>
            <?php if ($pageNumber === $currentPage): ?>
                <span
                    class="storefront-pagination-link is-current"
                    aria-current="page"
                ><?= $pageNumber ?></span>
            <?php else: ?>
                <a
                    class="storefront-pagination-link"
                    href="<?= $paginationEscape($collectionPath . '?page=' . $pageNumber) ?>"
                ><?= $pageNumber ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if (!empty($collection['has_next'])): ?>
            <a
                class="storefront-pagination-link"
                href="<?= $paginationEscape($collectionPath . '?page=' . ($currentPage + 1)) ?>"
            >
                <?= $paginationEscape(
                    Translator::t('storefront.pagination.next', 'Далі')
                ) ?>
            </a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
