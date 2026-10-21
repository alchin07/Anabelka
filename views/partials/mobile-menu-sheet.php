<?php
$mobileNavigationContext = is_array($mobileNavigationContext ?? null)
    ? $mobileNavigationContext
    : [];
$mobileMenuItems = is_array($mobileNavigationContext['items'] ?? null)
    ? $mobileNavigationContext['items']
    : [];
$mobileMenuTitle = (string) (
    $mobileNavigationContext['menu_label']
    ?? 'Меню'
);
$mobileMenuCloseLabel = (string) (
    $mobileNavigationContext['close_label']
    ?? 'Закрити меню'
);
$mobileMenuEscape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<div
    class="mobile-menu-layer"
    data-mobile-menu-layer
    aria-hidden="true"
    hidden
>
    <button
        type="button"
        class="mobile-menu-backdrop"
        data-mobile-menu-backdrop
        aria-label="<?= $mobileMenuEscape($mobileMenuCloseLabel) ?>"
    ></button>

    <section
        id="mobile-menu-sheet"
        class="mobile-menu-sheet"
        data-mobile-menu-sheet
        role="dialog"
        aria-modal="true"
        aria-labelledby="mobile-menu-title"
        tabindex="-1"
    >
        <header class="mobile-menu-sheet-header">
            <h2 id="mobile-menu-title">
                <?= $mobileMenuEscape($mobileMenuTitle) ?>
            </h2>

            <button
                type="button"
                class="mobile-menu-close"
                data-mobile-menu-close
                aria-label="<?= $mobileMenuEscape($mobileMenuCloseLabel) ?>"
            >
                ×
            </button>
        </header>

        <nav class="mobile-menu-links" aria-label="<?= $mobileMenuEscape($mobileMenuTitle) ?>">
            <?php foreach ($mobileMenuItems as $mobileMenuItem): ?>
                <?php
                $mobileMenuUrl = (string) ($mobileMenuItem['url'] ?? '');
                $mobileMenuName = (string) (
                    $mobileMenuItem['name']
                    ?? $mobileMenuItem['name_uk']
                    ?? ''
                );
                $mobileMenuExternal = !empty($mobileMenuItem['is_external']);

                if ($mobileMenuUrl === '' || $mobileMenuName === '') {
                    continue;
                }
                ?>
                <a
                    href="<?= $mobileMenuEscape($mobileMenuUrl) ?>"
                    data-mobile-menu-link
                    <?= $mobileMenuExternal
                        ? 'target="_blank" rel="noopener noreferrer"'
                        : '' ?>
                >
                    <span><?= $mobileMenuEscape($mobileMenuName) ?></span>
                    <span class="mobile-menu-link-arrow" aria-hidden="true">
                        <?= $mobileMenuExternal ? '↗' : '›' ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </nav>
    </section>
</div>
