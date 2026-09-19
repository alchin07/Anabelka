<?php
$mobileNavigationContext = is_array($mobileNavigationContext ?? null)
    ? $mobileNavigationContext
    : [];

$mobileNavHasAdmin = !empty($mobileNavigationContext['has_admin']);
$mobileNavColumns = $mobileNavHasAdmin ? 4 : 3;
$mobileNavProfileUrl = (string) (
    $mobileNavigationContext['profile_url']
    ?? '/Anabelka/login'
);
$mobileNavProfileLabel = (string) (
    $mobileNavigationContext['profile_label']
    ?? 'Вхід'
);
$mobileNavMenuLabel = (string) (
    $mobileNavigationContext['menu_label']
    ?? 'Меню'
);
$mobileNavEscape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<nav
    class="mobile-bottom-navigation"
    data-mobile-bottom-navigation
    aria-label="<?= $mobileNavEscape(
        $mobileNavigationContext['navigation_label']
        ?? 'Мобільна навігація'
    ) ?>"
    style="--mobile-bottom-navigation-columns: <?= $mobileNavColumns ?>"
>
    <button
        type="button"
        class="mobile-bottom-navigation-action"
        data-mobile-menu-toggle
        aria-controls="mobile-menu-sheet"
        aria-expanded="false"
    >
        <span class="mobile-bottom-navigation-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24">
                <path d="M4 7h16M4 12h16M4 17h16"></path>
            </svg>
        </span>
        <span><?= $mobileNavEscape($mobileNavMenuLabel) ?></span>
    </button>

    <a
        class="mobile-bottom-navigation-action mobile-bottom-navigation-profile"
        data-mobile-profile-action
        href="<?= $mobileNavEscape($mobileNavProfileUrl) ?>"
    >
        <span class="mobile-bottom-navigation-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24">
                <circle cx="12" cy="8" r="3.5"></circle>
                <path d="M5.5 20c.5-4 3-6 6.5-6s6 2 6.5 6"></path>
            </svg>
        </span>
        <span><?= $mobileNavEscape($mobileNavProfileLabel) ?></span>
    </a>

    <div
        class="mobile-bottom-navigation-slot"
        data-mobile-cart-slot
        aria-live="polite"
    ></div>

    <?php if ($mobileNavHasAdmin): ?>
        <div
            class="mobile-bottom-navigation-slot"
            data-mobile-admin-slot
            aria-live="polite"
        ></div>
    <?php endif; ?>
</nav>
