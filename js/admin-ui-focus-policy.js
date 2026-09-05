/*
 * Загальна політика фокусу для адмін-панелі «Анабельки».
 *
 * Програмні переходи, підсвічування та прокрутка до карток/форм
 * не повинні відкривати мобільну клавіатуру. Клавіатура має
 * з'являтися тільки після явного натискання користувача на поле.
 */
(function () {
    'use strict';

    function isEditableElement(element)
    {
        if (!element || element === document.body) {
            return false;
        }

        return element.matches(
            'input, textarea, select, [contenteditable="true"], [contenteditable=""]'
        );
    }

    function blurActiveField()
    {
        const active = document.activeElement;

        if (isEditableElement(active) && typeof active.blur === 'function') {
            active.blur();
        }
    }

    function prepareProgrammaticNavigation()
    {
        blurActiveField();

        /*
         * На Android клавіатура іноді відкривається із затримкою,
         * тому повторно прибираємо фокус після перебудови DOM/scroll.
         */
        window.setTimeout(blurActiveField, 0);
        window.setTimeout(blurActiveField, 120);
        window.setTimeout(blurActiveField, 320);
    }

    window.AdminUiFocusPolicy = {
        blurActiveField: blurActiveField,
        prepareProgrammaticNavigation: prepareProgrammaticNavigation
    };

    /*
     * Поточні переходи з центру перекладів використовують query
     * highlight/highlight_type. При такому вході жодне поле не повинно
     * залишатися сфокусованим автоматично.
     */
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
