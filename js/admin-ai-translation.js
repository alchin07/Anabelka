(function () {
    const switcher = document.getElementById('ai-provider-switcher');
    const select = document.getElementById('ai-provider-select');
    const status = document.getElementById('ai-provider-status');

    let providers = {};
    let selectedProvider = '';

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

    function renderProviders(data) {
        providers = data.providers || {};
        selectedProvider = data.selected_provider || '';

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

        if (switcher) {
            switcher.hidden = false;
        }

        updateStatus();
        updatePageIndicators();
    }

    function updatePageIndicators() {
        const currentProvider = providers[selectedProvider] || null;

        document
            .querySelectorAll('[data-ai-default-provider-name]')
            .forEach(function (element) {
                element.textContent = currentProvider
                    ? currentProvider.name
                    : '—';
            });

        document
            .querySelectorAll('[data-ai-provider-code]')
            .forEach(function (card) {
                const code = card.dataset.aiProviderCode || '';
                const provider = providers[code] || null;
                const label = card.querySelector(
                    '[data-ai-provider-current]'
                );

                card.classList.toggle(
                    'is-default',
                    code === selectedProvider
                );

                if (!label) {
                    return;
                }

                if (code === selectedProvider) {
                    label.textContent = 'За замовчуванням';
                } else if (provider && provider.configured) {
                    label.textContent = 'Доступний для вибору';
                } else {
                    label.textContent = 'Недоступний без ключа';
                }
            });
    }

    function updateStatus() {
        const provider = providers[selectedProvider];

        if (!provider) {
            setStatus('');
            return;
        }

        setStatus(
            provider.configured
                ? 'за замовчуванням'
                : 'потрібен ключ'
        );
    }

    async function loadProviders() {
        try {
            const data = await request(
                '/Anabelka/admin/ai-translation/providers'
            );

            renderProviders(data);
        } catch (error) {
            setStatus(error.message || 'помилка');
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

            if (data.providers) {
                renderProviders(data);
            } else {
                updateStatus();
            }

            return data.translation || {};

        } catch (error) {
            updateStatus();
            throw error;
        }
    }

    if (select) {
        select.addEventListener('change', async function () {
            const previous = selectedProvider;

            try {
                setStatus('збереження…');
                await chooseProvider(select.value);
            } catch (error) {
                select.value = previous;
                selectedProvider = previous;
                updateStatus();
                window.alert(error.message || 'Не вдалося змінити ШІ.');
            }
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

    loadProviders();
})();
