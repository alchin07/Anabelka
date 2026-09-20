(function () {
    'use strict';

    if (window.AnabelkaSelect) {
        return;
    }

    let sequence = 0;
    let openInstance = null;
    let categorySelectHistoryToken = 0;
    const categorySelectHistoryKey = '__anabelkaCategorySelect';
    const instances = new WeakMap();
    const allInstances = new Set();

    function currentSelectUrl()
    {
        return window.location.pathname
            + window.location.search
            + window.location.hash;
    }


    function isCategoryThumbnailSelect(instance)
    {
        return Boolean(
            instance
            && instance.select
            && instance.select.hasAttribute(
                'data-category-thumbnail-select'
            )
        );
    }


    function cleanCategorySelectState(source)
    {
        const state = source && typeof source === 'object'
            ? Object.assign({}, source)
            : {};

        delete state[categorySelectHistoryKey];

        return state;
    }


    function armCategorySelectHistory(instance)
    {
        if (!isCategoryThumbnailSelect(instance)) {
            return;
        }

        categorySelectHistoryToken += 1;

        const baseState = cleanCategorySelectState(history.state);

        history.replaceState(
            baseState,
            '',
            currentSelectUrl()
        );

        const selectState = Object.assign({}, baseState);

        selectState[categorySelectHistoryKey] =
            categorySelectHistoryToken;

        history.pushState(
            selectState,
            '',
            currentSelectUrl()
        );

        instance.categoryHistoryToken =
            categorySelectHistoryToken;
        instance.categoryHistoryArmed = true;
    }


    function disarmCategorySelectHistory(instance)
    {
        if (
            !isCategoryThumbnailSelect(instance)
            || !instance.categoryHistoryArmed
        ) {
            return;
        }

        instance.categoryHistoryArmed = false;
        history.back();
    }


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

    function close(instance, restoreFocus, options)
    {
        if (!instance || instance.list.hidden) {
            return;
        }

        const settings = options && typeof options === 'object'
            ? options
            : {};
        const syncHistory = settings.syncHistory !== false;

        instance.list.hidden = true;
        instance.trigger.setAttribute('aria-expanded', 'false');
        instance.wrapper.classList.remove('is-open', 'is-up');

        if (syncHistory) {
            disarmCategorySelectHistory(instance);
        }

        if (openInstance === instance) {
            openInstance = null;
        }

        if (
            window.AnabelkaAdminBack
            && typeof window.AnabelkaAdminBack.syncNow === 'function'
        ) {
            window.AnabelkaAdminBack.syncNow();
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

        sync(instance);
        updatePlacement(instance);
        instance.list.hidden = false;
        instance.trigger.setAttribute('aria-expanded', 'true');
        instance.wrapper.classList.add('is-open');
        openInstance = instance;

        if (isCategoryThumbnailSelect(instance)) {
            armCategorySelectHistory(instance);
        }

        if (
            window.AnabelkaAdminBack
            && typeof window.AnabelkaAdminBack.syncNow === 'function'
        ) {
            window.AnabelkaAdminBack.syncNow();
        }

        focusOnOpen(instance, focusMode || 'selected');
    }

    function optionLabel(option)
    {
        if (!option) {
            return 'Оберіть';
        }

        const custom = String(
            option.dataset.anabelkaLabel || ''
        ).trim();

        return custom !== ''
            ? custom
            : option.textContent.trim();
    }


    function renderPresentation(container, option)
    {
        const rich = Boolean(
            option
            && option.dataset.anabelkaRich === '1'
        );

        container.classList.toggle('is-rich', rich);
        container.replaceChildren();

        if (!option) {
            container.textContent = 'Оберіть';
            return;
        }

        if (!rich) {
            container.textContent = optionLabel(option);
            return;
        }

        const content = document.createElement('span');
        const thumbnail = document.createElement('span');
        const copy = document.createElement('span');
        const label = document.createElement('span');
        const subtitle = document.createElement('span');
        const path = String(
            option.dataset.anabelkaThumbnail || ''
        ).trim();
        const subtitleText = String(
            option.dataset.anabelkaSubtitle || ''
        ).trim();

        content.className = 'anabelka-select-rich-content';
        thumbnail.className = 'anabelka-select-thumbnail';
        copy.className = 'anabelka-select-rich-copy';
        label.className = 'anabelka-select-rich-label';
        subtitle.className = 'anabelka-select-rich-subtitle';

        if (option.dataset.anabelkaThumbnailEdit === '1') {
            thumbnail.classList.add('is-editable');
            thumbnail.dataset.anabelkaThumbnailControl = '';
            thumbnail.title = 'Редагувати мініатюру';
        }
        label.textContent = optionLabel(option);

        if (path !== '') {
            const image = document.createElement('img');

            image.src = path;
            image.alt = '';
            image.loading = 'lazy';
            image.addEventListener('error', function () {
                image.remove();
                thumbnail.classList.add('is-empty');
            });
            thumbnail.appendChild(image);
        } else {
            thumbnail.classList.add('is-empty');
        }

        copy.appendChild(label);

        if (subtitleText !== '') {
            subtitle.textContent = subtitleText;
            copy.appendChild(subtitle);
        }

        content.appendChild(thumbnail);
        content.appendChild(copy);
        container.appendChild(content);
    }


    function levelBackground(depth)
    {
        const colors = [
            '#ffffff',
            '#faf7ff',
            '#f4eaff',
            '#eadcf7',
            '#e4cef8',
            '#dcc2f0'
        ];

        depth = Math.max(0, Number(depth || 0));

        return colors[Math.min(depth, colors.length - 1)];
    }


    function sync(instance)
    {
        const selected = instance.select.options[instance.select.selectedIndex] || null;

        renderPresentation(instance.triggerLabel, selected);
        instance.trigger.disabled = instance.select.disabled;

        Array.from(
            instance.list.querySelectorAll('.anabelka-select-option')
        ).forEach(function (button) {
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

    function renderOptions(instance)
    {
        const wasOpen = instance.trigger.getAttribute('aria-expanded') === 'true';

        instance.list.innerHTML = '';

        Array.from(instance.select.options).forEach(function (option, index) {
            const button = document.createElement('button');

            button.type = 'button';
            button.className = 'anabelka-select-option';
            button.dataset.optionIndex = String(index);
            button.setAttribute('role', 'option');
            button.setAttribute('aria-selected', option.selected ? 'true' : 'false');
            button.disabled = option.disabled || instance.select.disabled;

            if (option.dataset.anabelkaRich === '1') {
                const depth = Math.max(
                    0,
                    Number(option.dataset.anabelkaDepth || 0)
                );

                button.classList.add('is-rich');
                button.dataset.depth = String(depth);
                button.style.setProperty(
                    '--anabelka-select-level-bg',
                    levelBackground(depth)
                );
            }

            renderPresentation(button, option);

            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const thumbnail = event.target.closest(
                    '[data-anabelka-thumbnail-control]'
                );

                if (
                    thumbnail
                    && button.contains(thumbnail)
                    && option.dataset.anabelkaThumbnailEdit === '1'
                ) {
                    instance.select.dispatchEvent(new CustomEvent(
                        'anabelka:thumbnail-edit',
                        {
                            bubbles: true,
                            detail: {
                                optionIndex: index,
                                option: option,
                                button: button,
                                list: instance.list,
                                thumbnail: thumbnail
                            }
                        }
                    ));
                    return;
                }

                choose(instance, index);
            });

            instance.list.appendChild(button);
        });

        sync(instance);

        if (wasOpen) {
            updatePlacement(instance);
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
        if (!(select instanceof HTMLSelectElement)) {
            return null;
        }

        if (select.dataset.anabelkaSelectReady === '1') {
            return instances.get(select) || null;
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
            list: list,
            observer: null,
            categoryHistoryToken: 0,
            categoryHistoryArmed: false
        };

        instances.set(select, instance);
        allInstances.add(instance);
        renderOptions(instance);

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

        select.addEventListener('focus', function () {
            trigger.focus();
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

        instance.observer = new MutationObserver(function () {
            renderOptions(instance);
        });
        instance.observer.observe(select, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true,
            attributeFilter: ['disabled', 'selected', 'label']
        });

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

    function markSelects(selectors)
    {
        selectors.forEach(function (selector) {
            document.querySelectorAll(selector).forEach(function (select) {
                select.setAttribute('data-anabelka-select', '');
            });
        });
    }

    function markPageSelects()
    {
        const path = window.location.pathname.replace(/\/$/, '');

        if (path === '/Anabelka/admin/users') {
            markSelects([
                'select[name="invite_channel"]',
                'select[name="invite_rank_id"]',
                '.admin-users-filters select[name="rank_id"]',
                '.admin-users-filters select[name="status"]',
                '.admin-user-rank-form select[name="rank_id"]'
            ]);
        }

        if (path === '/Anabelka/admin/categories') {
            markSelects([
                '#category-create-department',
                '#category-move-parent',
                '#category-move-department',
                '.category-translation-status select'
            ]);
        }
    }

    function syncAll()
    {
        allInstances.forEach(function (instance) {
            sync(instance);
        });
    }

    document.addEventListener('click', function (event) {
        if (openInstance && !openInstance.wrapper.contains(event.target)) {
            close(openInstance, false);
        }

        window.requestAnimationFrame(syncAll);
    });

    document.addEventListener('input', function () {
        window.requestAnimationFrame(syncAll);
    });

    window.addEventListener('resize', function () {
        if (openInstance) {
            updatePlacement(openInstance);
        }
    });

    window.addEventListener('popstate', function (event) {
        if (!isCategoryThumbnailSelect(openInstance)) {
            return;
        }

        const state = event.state
            && typeof event.state === 'object'
            ? event.state
            : {};
        const stateToken = Number(
            state[categorySelectHistoryKey] || 0
        );
        const instanceToken = Number(
            openInstance.categoryHistoryToken || 0
        );

        /*
         * Back from the thumbnail editor lands on this select-owned
         * history entry. Keep the category list open. The next Back
         * lands on the product editor entry and closes the list.
         */
        if (
            stateToken > 0
            && stateToken === instanceToken
        ) {
            openInstance.categoryHistoryArmed = true;
            return;
        }

        openInstance.categoryHistoryArmed = false;
        close(openInstance, true, {
            syncHistory: false
        });
    });


    if (
        window.AnabelkaAdminBack
        && typeof window.AnabelkaAdminBack.register === 'function'
    ) {
        window.AnabelkaAdminBack.register({
            key: 'anabelka-select',
            priority: 90,
            isActive: function () {
                return Boolean(
                    openInstance
                    && !isCategoryThumbnailSelect(openInstance)
                    && openInstance.trigger.getAttribute('aria-expanded')
                        === 'true'
                );
            },
            close: function () {
                if (openInstance) {
                    close(openInstance, true);
                }
            }
        });
    }

    window.AnabelkaSelect = {
        enhance: enhance,
        enhanceAll: enhanceAll,
        refresh: function (select) {
            if (!(select instanceof HTMLSelectElement)) {
                return false;
            }

            const instance = instances.get(select) || enhance(select);

            if (!instance) {
                return false;
            }

            renderOptions(instance);
            return true;
        },
        sync: function (select) {
            if (!(select instanceof HTMLSelectElement)) {
                return false;
            }

            const instance = instances.get(select) || enhance(select);

            if (!instance) {
                return false;
            }

            sync(instance);
            return true;
        }
    };

    function init()
    {
        if (
            history.state
            && typeof history.state === 'object'
            && history.state[categorySelectHistoryKey]
        ) {
            history.replaceState(
                cleanCategorySelectState(history.state),
                '',
                currentSelectUrl()
            );
        }

        markPageSelects();
        enhanceAll(document);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once: true});
    } else {
        init();
    }
}());
