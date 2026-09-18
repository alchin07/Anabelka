(function () {
    'use strict';

    if (
        window.AnabelkaMobileNavigation
        && window.AnabelkaMobileNavigation.initialized
    ) {
        return;
    }

    const media = window.matchMedia('(max-width: 430px)');
    const root = document.documentElement;
    const bottomNavigation = document.querySelector(
        '[data-mobile-bottom-navigation]'
    );
    const menuToggle = document.querySelector('[data-mobile-menu-toggle]');
    const profileAction = document.querySelector(
        '[data-mobile-profile-action]'
    );
    const cartSlot = document.querySelector('[data-mobile-cart-slot]');
    const adminSlot = document.querySelector('[data-mobile-admin-slot]');
    const menuLayer = document.querySelector('[data-mobile-menu-layer]');
    const menuSheet = document.querySelector('[data-mobile-menu-sheet]');
    const menuBackdrop = document.querySelector('[data-mobile-menu-backdrop]');
    const menuClose = document.querySelector('[data-mobile-menu-close]');
    const profileMenu = document.querySelector('.public-header-profile');
    const profileBadge = document.getElementById(
        'profile-notification-count'
    );
    const cartAction = document.querySelector('.header-cart');
    const adminAction = document.querySelector(
        '.public-header-admin-action'
    );

    if (
        !bottomNavigation
        || !menuToggle
        || !profileAction
        || !cartSlot
        || !menuLayer
        || !menuSheet
        || !cartAction
        || !profileMenu
        || !profileBadge
        || (adminAction && !adminSlot)
    ) {
        return;
    }

    function makeHome(node, label) {
        const anchor = document.createComment(
            'anabelka-mobile-navigation-home:' + label
        );

        node.parentNode.insertBefore(anchor, node);

        return {
            node: node,
            anchor: anchor
        };
    }

    function restoreHome(entry) {
        if (
            !entry
            || !entry.anchor
            || !entry.anchor.parentNode
        ) {
            return;
        }

        entry.anchor.parentNode.insertBefore(
            entry.node,
            entry.anchor.nextSibling
        );
    }

    const homes = {
        cart: makeHome(cartAction, 'cart'),
        profileBadge: makeHome(profileBadge, 'profile-badge'),
        admin: adminAction
            ? makeHome(adminAction, 'admin')
            : null
    };

    let menuOpen = false;
    let mobileApplied = false;
    let previousFocus = null;

    function openMenu() {
        if (!media.matches || menuOpen) {
            return false;
        }

        menuOpen = true;
        previousFocus = document.activeElement;
        menuLayer.hidden = false;
        menuLayer.setAttribute('aria-hidden', 'false');
        menuToggle.setAttribute('aria-expanded', 'true');
        root.classList.add('anabelka-mobile-menu-open');

        window.requestAnimationFrame(function () {
            menuClose?.focus();
        });

        return true;
    }

    function closeMenu(options) {
        if (!menuOpen) {
            return false;
        }

        const settings = options || {};
        menuOpen = false;
        menuLayer.hidden = true;
        menuLayer.setAttribute('aria-hidden', 'true');
        menuToggle.setAttribute('aria-expanded', 'false');
        root.classList.remove('anabelka-mobile-menu-open');

        if (
            settings.restoreFocus !== false
            && previousFocus
            && typeof previousFocus.focus === 'function'
        ) {
            previousFocus.focus();
        }

        previousFocus = null;
        return true;
    }

    function applyMobile() {
        if (!media.matches || mobileApplied) {
            return;
        }

        profileMenu.open = false;
        profileAction.appendChild(profileBadge);
        cartSlot.appendChild(cartAction);

        if (adminAction && adminSlot) {
            adminSlot.appendChild(adminAction);
        }

        mobileApplied = true;
    }

    function restoreDesktop() {
        if (!mobileApplied) {
            closeMenu({restoreFocus: false});
            return;
        }

        closeMenu({restoreFocus: false});
        restoreHome(homes.profileBadge);
        restoreHome(homes.cart);
        restoreHome(homes.admin);
        mobileApplied = false;
    }

    function syncViewport() {
        if (media.matches) {
            applyMobile();
            return;
        }

        restoreDesktop();
    }

    menuToggle.addEventListener('click', openMenu);
    menuClose?.addEventListener('click', function () {
        closeMenu();
    });
    menuBackdrop?.addEventListener('click', function () {
        closeMenu();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && menuOpen) {
            event.preventDefault();
            closeMenu();
        }
    });

    if (typeof media.addEventListener === 'function') {
        media.addEventListener('change', syncViewport);
    } else if (typeof media.addListener === 'function') {
        media.addListener(syncViewport);
    }

    root.classList.add('has-mobile-navigation');
    syncViewport();

    window.AnabelkaMobileNavigation = {
        initialized: true,
        openMenu: openMenu,
        closeMenu: closeMenu,
        isOpen: function () {
            return menuOpen;
        }
    };
})();
