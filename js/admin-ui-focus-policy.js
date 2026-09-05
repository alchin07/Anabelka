/*
 * Загальна політика фокусу для адмін-панелі «Анабельки».
 *
 * Програмні переходи, підсвічування та відкриття редакторів не повинні
 * відкривати мобільну клавіатуру. Клавіатура з'являється тільки після
 * явного натискання користувача безпосередньо на поле вводу.
 */
(function () {
    'use strict';

    let suppressEditableFocusUntil = 0;

    function isEditableElement(element)
    {
        if (!element || element === document.body || !element.matches) {
            return false;
        }

        return element.matches(
            'input:not([type="hidden"]), textarea, select, '
            + '[contenteditable="true"], [contenteditable=""]'
        );
    }

    function blurActiveField()
    {
        const active = document.activeElement;

        if (isEditableElement(active) && typeof active.blur === 'function') {
            active.blur();
        }
    }

    function suppressProgrammaticFocus(duration)
    {
        const milliseconds = Number(duration || 500);
        suppressEditableFocusUntil = Math.max(
            suppressEditableFocusUntil,
            Date.now() + milliseconds
        );

        blurActiveField();
        window.setTimeout(blurActiveField, 0);
        window.setTimeout(blurActiveField, 40);
        window.setTimeout(blurActiveField, 90);
        window.setTimeout(blurActiveField, 180);
        window.setTimeout(blurActiveField, 360);
    }

    function prepareProgrammaticNavigation()
    {
        suppressProgrammaticFocus(650);
    }

    window.AdminUiFocusPolicy = {
        blurActiveField: blurActiveField,
        suppressProgrammaticFocus: suppressProgrammaticFocus,
        prepareProgrammaticNavigation: prepareProgrammaticNavigation
    };

    /*
     * Якщо натискання було по звичайній кнопці/посиланню, а скрипт після
     * цього намагається поставити курсор у input/textarea/select, такий
     * фокус скасовуємо. Якщо користувач натиснув саме на поле — дозволяємо.
     */
    document.addEventListener('pointerdown', function (event) {
        if (isEditableElement(event.target)) {
            suppressEditableFocusUntil = 0;
            return;
        }

        const control = event.target.closest(
            'button, a, [role="button"], '
            + '[data-product-edit], .edit-button, .category-edit-button, '
            + '[data-edit], [data-open], [data-modal-open]'
        );

        if (control) {
            suppressProgrammaticFocus(650);
        }
    }, true);

    document.addEventListener('focusin', function (event) {
        if (
            Date.now() < suppressEditableFocusUntil
            && isEditableElement(event.target)
        ) {
            event.target.blur();
        }
    }, true);

    function init()
    {
        const params = new URLSearchParams(window.location.search);

        if (params.has('highlight') || params.has('highlight_type')) {
            prepareProgrammaticNavigation();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
