<?php
$mobileShortcutEscape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$mobileHeaderShortcuts = [
    [
        'href' => '/Anabelka/news',
        'label' => Translator::t('mobile_navigation.news', 'Новини'),
        'icon' => 'news'
    ],
    [
        'href' => '/Anabelka/reviews',
        'label' => Translator::t('mobile_navigation.reviews', 'Відгуки'),
        'icon' => 'reviews'
    ],
    [
        'href' => '/Anabelka/gift-certificates',
        'label' => Translator::t(
            'mobile_navigation.gift_certificates',
            'Подарункові сертифікати'
        ),
        'icon' => 'gift'
    ]
];
?>
<nav
    class="mobile-header-shortcuts"
    aria-label="<?= $mobileShortcutEscape(
        Translator::t('mobile_navigation.shortcuts', 'Швидкі посилання')
    ) ?>"
>
    <?php foreach ($mobileHeaderShortcuts as $mobileShortcut): ?>
        <a
            href="<?= $mobileShortcutEscape($mobileShortcut['href']) ?>"
            class="mobile-header-shortcut"
            aria-label="<?= $mobileShortcutEscape($mobileShortcut['label']) ?>"
            title="<?= $mobileShortcutEscape($mobileShortcut['label']) ?>"
        >
            <?php if ($mobileShortcut['icon'] === 'news'): ?>
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="4" y="5" width="16" height="14" rx="2"></rect>
                    <path d="M8 9h8M8 13h8M8 17h5"></path>
                </svg>
            <?php elseif ($mobileShortcut['icon'] === 'reviews'): ?>
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M5 5.5h14v10H10l-4.5 3v-3H5z"></path>
                    <path d="M9 10h6"></path>
                </svg>
            <?php else: ?>
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 10h16v10H4zM3 7h18v3H3zM12 7v13"></path>
                    <path d="M12 7c-1.4-3.2-5.4-3.6-5.4-.7 0 1.7 2.1 2.2 5.4.7Zm0 0c1.4-3.2 5.4-3.6 5.4-.7 0 1.7-2.1 2.2-5.4.7Z"></path>
                </svg>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
