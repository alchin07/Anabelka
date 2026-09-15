(function () {
    'use strict';

    const switcher = document.getElementById('ai-provider-switcher');
    const select = document.getElementById('ai-provider-select');
    const status = document.getElementById('ai-provider-status');
    const trigger = document.getElementById('ai-provider-trigger');
    const triggerLabel = document.getElementById('ai-provider-trigger-label');
    const optionsList = document.getElementById('ai-provider-options');
    const floatingRoot = document.getElementById('admin-ai-top-slot');

    let providers = {};
    let selectedProvider = '';
    let isSaving = false;
    let floatingAssetsPromise = null;

    function ensureFloatingAssets() {
        if (!document.querySelector('link[data-anabelka-floating-tool]')) {
            const stylesheet = document.createElement('link');
            stylesheet.rel = 'stylesheet';
            stylesheet.href = '/Anabelka/css/anabelka-floating-tool.css?v=1';
            stylesheet.dataset.anabelkaFloatingTool = '1';
            document.head.appendChild(stylesheet);
        }

        if (window.AnabelkaFloatingTool) {
            return Promise.resolve(window.AnabelkaFloatingTool);
        }

        if (floatingAssetsPromise) {
            return floatingAssetsPromise;
        }

        floatingAssetsPromise = new Promise(function (resolve) {
            let script = document.querySelector(
                'script[data-anabelka-floating-tool]'
            );

            function finish() {
                resolve(window.AnabelkaFloatingTool || null);
            }

            if (script) {
                script.addEventListener('load', finish, {once: true});
                script.addEventListener('error', function () {
                    resolve(null);
                }, {once: true});
                return;
            }

            script = document.createElement('script');
            script.src = '/Anabelka/js/anabelka-floating-tool.js?v=1';
            script.dataset.anabelkaFloatingTool = '1';
            script.addEventListener('load', finish, {once: true});
            script.addEventListener('error', function () {
                resolve(null);
            }, {once: true});
            document.body.appendChild(script);
        });

        return floatingAssetsPromise;
    }

    function prepareFloatingSwitcher() {
        if (!switcher || !floatingRoot) {
            return;
        }

        floatingRoot.setAttribute('data-anabelka-floating-tool', '');
        floatingRoot.setAttribute('data-anabelka-floating-key', 'ai-provider');

        if (!switcher.querySelector('[data-anabelka-drag-handle]')) {
            const handle = document.createElement('button');
            handle.type = 'button';
            handle.className = 'anabelka-floating-drag-handle';
            handle.setAttribute('data-anabelka-drag-handle', '');
            handle.setAttribute('aria-label', 'Перемістити панель ШІ');
            handle.title = 'Перемістити панель ШІ';
            handle.textContent = '⠿';
            switcher.insertBefore(handle, switcher.firstChild);
        }

        ensureFloatingAssets();
    }

    function activateFloatingSwitcher() {
        if (!floatingRoot) {
            return;
        }

        ensureFloatingAssets().then(function (floatingTool) {
            if (!floatingTool) {
                return;
            }

            floatingTool.register(floatingRoot, {
                storageKey: 'ai-provider'
            });
        });
    }

    function setStatus(text) {
        if (status) {
            status.textContent = text || '';
        }
    }

    async function request(url, options) {
        const response = await fetch(url, options || {});
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Помилка ШІ-перекладу.');
        }

        return data;
    }

    function updateStatus() {
        const provider = providers[selectedProvider];

        if (!provider) {
            setStatus('');
            return;
        }

        setStatus(
            provider.configured
                ? 'готово'
                : 'потрібен ключ'
        );
    }

    function setExpanded(expanded, focusMode) {
        if (!trigger || !optionsList) {
            return;
        }

        const open = Boolean(expanded);
        optionsList.hidden = !open;
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        switcher?.classList.toggle('is-provider-open', open);

        if (!open || !focusMode) {
            return;
        }

        window.requestAnimationFrame(function () {
            const enabled = Array.from(
                optionsList.querySelectorAll(
                    '.ai-provider-option:not([disabled])'
                )
            );

            if (enabled.length === 0) {
                return;
            }

            if (focusMode === 'last') {
                enabled[enabled.length - 1].focus();
                return;
            }

            const selected = enabled.find(function (item) {
                return item.getAttribute('aria-selected') === 'true';
            });
            (selected || enabled[0]).focus();
        });
    }

    function enabledOptions() {
        if (!optionsList) {
            return [];
        }

        return Array.from(
            optionsList.querySelectorAll(
                '.ai-provider-option:not([disabled])'
            )
        );
    }

    function focusRelativeOption(direction) {
        const items = enabledOptions();

        if (items.length === 0) {
            return;
        }

        const index = items.indexOf(document.activeElement);
        const nextIndex = index < 0
            ? 0
            : (index + direction + items.length) % items.length;

        items[nextIndex].focus();
    }

    function renderNativeSelect() {
        if (!select) {
            return;
        }

        select.innerHTML = '';

        Object.keys(providers).forEach(function (code) {
            const provider = providers[code];
            const option = document.createElement('option');

            option.value = code;
            option.textContent = provider.name
                + (provider.configured ? '' : ' · не налаштовано');
            option.selected = code === selectedProvider;
            option.disabled = !provider.configured;

            select.appendChild(option);
        });
    }

    function renderBrandedOptions() {
        if (!optionsList) {
            return;
        }

        optionsList.innerHTML = '';

        Object.keys(providers).forEach(function (code) {
            const provider = providers[code];
            const option = document.createElement('button');
            const name = document.createElement('span');
            const meta = document.createElement('span');

            option.type = 'button';
            option.className = 'ai-provider-option';
            option.dataset.provider = code;
            option.setAttribute('role', 'option');
            option.setAttribute(
                'aria-selected',
                code === selectedProvider ? 'true' : 'false'
            );
            option.disabled = !provider.configured || isSaving;

            name.className = 'ai-provider-option-name';
            name.textContent = provider.name;

            meta.className = 'ai-provider-option-meta';
            meta.textContent = provider.configured
                ? (code === selectedProvider ? 'Обрано' : 'Готово')
                : 'Не налаштовано';

            option.appendChild(name);
            option.appendChild(meta);
            option.addEventListener('click', function () {
                persistProvider(code);
            });
            optionsList.appendChild(option);
        });
    }

    function syncTrigger() {
        if (!trigger || !triggerLabel) {
            return;
        }

        const provider = providers[selectedProvider];
        const hasConfigured = Object.keys(providers).some(function (code) {
            return Boolean(providers[code]?.configured);
        });

        triggerLabel.textContent = provider?.name || 'Оберіть ШІ';
        trigger.disabled = isSaving || !hasConfigured;
    }

    function renderControls() {
        renderNativeSelect();
        renderBrandedOptions();
        syncTrigger();
    }

    function renderProviders(data) {
        providers = data.providers || {};
        selectedProvider = data.selected_provider || '';

        renderControls();

        if (switcher) {
            switcher.hidden = false;
            activateFloatingSwitcher();
        }

        updateStatus();
    }

    async function loadProviders() {
        try {
            const data = await request(
                '/Anabelka/admin/ai-translation/providers'
            );

            renderProviders(data);
        } catch (error) {
            setStatus(error.message || 'помилка');

            if (window.AnabelkaNotify) {
                window.AnabelkaNotify.error(
                    error.message || 'Не вдалося завантажити список ШІ.'
                );
            }
        }
    }

    async function chooseProvider(providerCode) {
        const formData = new FormData();
        formData.append('provider', providerCode);

        const data = await request(
            '/Anabelka/admin/ai-translation/provider',
            {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }
        );

        renderProviders(data);
        return selectedProvider;
    }

    async function persistProvider(providerCode) {
        if (
            isSaving
            || !providers[providerCode]
            || !providers[providerCode].configured
        ) {
            return;
        }

        if (providerCode === selectedProvider) {
            setExpanded(false);
            trigger?.focus();
            return;
        }

        const previous = selectedProvider;
        isSaving = true;
        setExpanded(false);
        setStatus('збереження…');
        renderControls();

        try {
            await chooseProvider(providerCode);
        } catch (error) {
            selectedProvider = previous;
            renderControls();
            updateStatus();

            if (window.AnabelkaNotify) {
                window.AnabelkaNotify.error(
                    error.message || 'Не вдалося змінити ШІ.'
                );
            } else {
                setStatus(error.message || 'помилка');
            }
        } finally {
            isSaving = false;
            renderControls();
            updateStatus();
            trigger?.focus();
        }
    }

    async function suggest(options) {
        const formData = new FormData();

        formData.append(
            'target_language',
            options.targetLanguage || ''
        );
        formData.append('name', options.name || '');
        formData.append('description', options.description || '');
        formData.append('context', options.context || 'catalog');
        formData.append(
            'provider',
            options.provider || selectedProvider || ''
        );

        setStatus('переклад…');

        try {
            const data = await request(
                '/Anabelka/admin/ai-translation/suggest',
                {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );

            const translation = data.translation || {};

            if (data.providers) {
                renderProviders(data);
            } else {
                updateStatus();
            }

            if (translation.fallback_used) {
                setStatus(
                    'резерв: '
                    + (translation.provider_name || translation.provider || '')
                );
            }

            return translation;

        } catch (error) {
            updateStatus();
            throw error;
        }
    }

    if (trigger) {
        trigger.addEventListener('click', function () {
            setExpanded(
                trigger.getAttribute('aria-expanded') !== 'true',
                'selected'
            );
        });

        trigger.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setExpanded(true, 'selected');
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setExpanded(true, 'last');
            }
        });
    }

    if (optionsList) {
        optionsList.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                setExpanded(false);
                trigger?.focus();
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                focusRelativeOption(1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                focusRelativeOption(-1);
            } else if (event.key === 'Home') {
                event.preventDefault();
                enabledOptions()[0]?.focus();
            } else if (event.key === 'End') {
                event.preventDefault();
                const items = enabledOptions();
                items[items.length - 1]?.focus();
            }
        });
    }

    document.addEventListener('click', function (event) {
        if (
            switcher
            && !switcher.contains(event.target)
            && trigger?.getAttribute('aria-expanded') === 'true'
        ) {
            setExpanded(false);
        }
    });

    if (select) {
        select.addEventListener('change', function () {
            persistProvider(select.value);
        });
    }

    window.AnabelkaAITranslation = {
        loadProviders: loadProviders,
        chooseProvider: chooseProvider,
        suggest: suggest,
        getProvider: function () {
            return selectedProvider;
        },
        getProviders: function () {
            return providers;
        }
    };

    prepareFloatingSwitcher();
    loadProviders();
})();
