(function () {
    'use strict';

    if (window.AnabelkaSelect) {
        return;
    }

    let sequence = 0;
    let openInstance = null;

    function optionButtons(instance)
    {
        return Array.from(
            instance.list.querySelectorAll('.anabelka-select-option:not([disabled])')
        );
    }

    function selectedButton(instance)
    {
        return optionButtons(instance).find(function (button) {
            return button.getAttribute('aria-selected') === 'true';
        }) || null;
    }

    function updatePlacement(instance)
    {
        const rect = instance.trigger.getBoundingClientRect();
        const below = window.innerHeight - rect.bottom;
        const above = rect.top;

        instance.wrapper.classList.toggle(
            'is-up',
            below < 220 && above > below
        );
    }

    function close(instance, restoreFocus)
    {
        if (!instance || instance.list.hidden) {
            return;
        }

        instance.list.hidden = true;
        instance.trigger.setAttribute('aria-expanded', 'false');
        instance.wrapper.classList.remove('is-open', 'is-up');

        if (openInstance === instance) {
            openInstance = null;
        }

        if (restoreFocus !== false) {
            instance.trigger.focus();
        }
    }

    function focusOnOpen(instance, mode)
    {
        window.requestAnimationFrame(function () {
            const buttons = optionButtons(instance);

            if (buttons.length === 0) {
                return;
            }

            if (mode === 'last') {
                buttons[buttons.length - 1].focus();
                return;
            }

            (selectedButton(instance) || buttons[0]).focus();
        });
    }

    function open(instance, focusMode)
    {
        if (instance.select.disabled || instance.trigger.disabled) {
            return;
        }

        if (openInstance && openInstance !== instance) {
            close(openInstance, false);
        }

        updatePlacement(instance);
        instance.list.hidden = false;
        instance.trigger.setAttribute('aria-expanded', 'true');
        instance.wrapper.classList.add('is-open');
        openInstance = instance;
        focusOnOpen(instance, focusMode || 'selected');
    }

    function sync(instance)
    {
        const selected = instance.select.options[instance.select.selectedIndex] || null;

        instance.triggerLabel.textContent = selected
            ? selected.textContent.trim()
            : 'Оберіть';
        instance.trigger.disabled = instance.select.disabled;

        Array.from(instance.list.children).forEach(function (button) {
            const option = instance.select.options[Number(button.dataset.optionIndex)];
            const isSelected = Boolean(option && option.selected);

            button.disabled = Boolean(!option || option.disabled || instance.select.disabled);
            button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
    }

    function choose(instance, optionIndex)
    {
        const option = instance.select.options[optionIndex];

        if (!option || option.disabled || instance.select.disabled) {
            return;
        }

        const changed = instance.select.selectedIndex !== optionIndex;
        instance.select.selectedIndex = optionIndex;
        sync(instance);
        close(instance, true);

        if (changed) {
            instance.select.dispatchEvent(new Event('change', {
                bubbles: true
            }));
        }
    }

    function focusRelative(instance, direction)
    {
        const buttons = optionButtons(instance);

        if (buttons.length === 0) {
            return;
        }

        const currentIndex = buttons.indexOf(document.activeElement);
        const nextIndex = currentIndex < 0
            ? 0
            : (currentIndex + direction + buttons.length) % buttons.length;

        buttons[nextIndex].focus();
    }

    function labelTextFor(select)
    {
        const label = select.closest('label');

        if (!label) {
            return select.getAttribute('aria-label') || select.name || 'Вибір';
        }

        const directSpan = Array.from(label.children).find(function (child) {
            return child.tagName === 'SPAN';
        });

        return directSpan
            ? directSpan.textContent.trim()
            : (select.getAttribute('aria-label') || select.name || 'Вибір');
    }

    function enhance(select)
    {
        if (
            !(select instanceof HTMLSelectElement)
            || select.dataset.anabelkaSelectReady === '1'
        ) {
            return null;
        }

        select.dataset.anabelkaSelectReady = '1';
        sequence += 1;

        const baseId = select.id || 'anabelka-select-' + sequence;
        const wrapper = document.createElement('div');
        const trigger = document.createElement('button');
        const triggerLabel = document.createElement('span');
        const chevron = document.createElement('span');
        const list = document.createElement('div');
        const label = select.closest('label');

        wrapper.className = 'anabelka-select';
        trigger.type = 'button';
        trigger.className = 'anabelka-select-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-controls', baseId + '-listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-label', labelTextFor(select));

        triggerLabel.className = 'anabelka-select-trigger-label';
        chevron.className = 'anabelka-select-chevron';
        chevron.setAttribute('aria-hidden', 'true');
        chevron.textContent = '⌄';

        list.id = baseId + '-listbox';
        list.className = 'anabelka-select-options';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', labelTextFor(select));
        list.hidden = true;

        Array.from(select.options).forEach(function (option, index) {
            const button = document.createElement('button');

            button.type = 'button';
            button.className = 'anabelka-select-option';
            button.dataset.optionIndex = String(index);
            button.setAttribute('role', 'option');
            button.setAttribute('aria-selected', option.selected ? 'true' : 'false');
            button.disabled = option.disabled || select.disabled;
            button.textContent = option.textContent.trim();

            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                choose(instance, index);
            });

            list.appendChild(button);
        });

        trigger.appendChild(triggerLabel);
        trigger.appendChild(chevron);
        wrapper.appendChild(trigger);
        wrapper.appendChild(list);

        select.parentNode.insertBefore(wrapper, select);
        wrapper.insertBefore(select, trigger);

        select.classList.add('anabelka-select-native');
        select.setAttribute('aria-hidden', 'true');
        select.tabIndex = -1;

        const instance = {
            select: select,
            wrapper: wrapper,
            trigger: trigger,
            triggerLabel: triggerLabel,
            list: list
        };

        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            if (trigger.getAttribute('aria-expanded') === 'true') {
                close(instance, false);
            } else {
                open(instance, 'selected');
            }
        });

        trigger.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                open(instance, 'selected');
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                open(instance, 'last');
            }
        });

        list.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(instance, true);
            } else if (event.key === 'ArrowDown') {
                event.preventDefault();
                focusRelative(instance, 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                focusRelative(instance, -1);
            } else if (event.key === 'Home') {
                event.preventDefault();
                optionButtons(instance)[0]?.focus();
            } else if (event.key === 'End') {
                event.preventDefault();
                const buttons = optionButtons(instance);
                buttons[buttons.length - 1]?.focus();
            }
        });

        select.addEventListener('change', function () {
            sync(instance);
        });

        if (select.form) {
            select.form.addEventListener('reset', function () {
                window.setTimeout(function () {
                    sync(instance);
                }, 0);
            });
        }

        if (label) {
            label.addEventListener('click', function (event) {
                if (
                    event.target === label
                    || (event.target.tagName === 'SPAN' && event.target.parentElement === label)
                ) {
                    event.preventDefault();
                    trigger.focus();
                }
            });
        }

        sync(instance);
        return instance;
    }

    function enhanceAll(root)
    {
        const scope = root && typeof root.querySelectorAll === 'function'
            ? root
            : document;

        return Array.from(
            scope.querySelectorAll('select[data-anabelka-select]')
        ).map(enhance).filter(Boolean);
    }

    function markUserPageSelects()
    {
        const path = window.location.pathname.replace(/\/$/, '');

        if (path !== '/Anabelka/admin/users') {
            return;
        }

        [
            'select[name="invite_channel"]',
            'select[name="invite_rank_id"]',
            '.admin-users-filters select[name="rank_id"]',
            '.admin-users-filters select[name="status"]',
            '.admin-user-rank-form select[name="rank_id"]'
        ].forEach(function (selector) {
            document.querySelectorAll(selector).forEach(function (select) {
                select.setAttribute('data-anabelka-select', '');
            });
        });
    }

    document.addEventListener('click', function (event) {
        if (openInstance && !openInstance.wrapper.contains(event.target)) {
            close(openInstance, false);
        }
    });

    window.addEventListener('resize', function () {
        if (openInstance) {
            updatePlacement(openInstance);
        }
    });

    window.AnabelkaSelect = {
        enhance: enhance,
        enhanceAll: enhanceAll,
        refresh: function (select) {
            if (select && select.dataset.anabelkaSelectReady === '1') {
                const wrapper = select.closest('.anabelka-select');
                const trigger = wrapper?.querySelector('.anabelka-select-trigger');
                const list = wrapper?.querySelector('.anabelka-select-options');

                if (trigger && list) {
                    const instance = {
                        select: select,
                        wrapper: wrapper,
                        trigger: trigger,
                        triggerLabel: trigger.querySelector('.anabelka-select-trigger-label'),
                        list: list
                    };
                    sync(instance);
                }
            }
        }
    };

    function init()
    {
        markUserPageSelects();
        enhanceAll(document);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once: true});
    } else {
        init();
    }
}());
