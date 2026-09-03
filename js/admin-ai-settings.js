(function () {
    'use strict';

    const defaultSelect = document.getElementById(
        'default-ai-provider'
    );
    const fallbackSelect = document.getElementById(
        'fallback-ai-provider'
    );

    function syncFallbackOptions() {
        if (!defaultSelect || !fallbackSelect) {
            return;
        }

        Array.from(fallbackSelect.options).forEach(function (option) {
            if (!option.dataset.initialized) {
                option.dataset.initialized = '1';
                option.dataset.unavailable = option.disabled ? '1' : '0';
            }

            const matchesDefault = option.value !== ''
                && option.value === defaultSelect.value;

            option.disabled = option.dataset.unavailable === '1'
                || matchesDefault;
        });

        if (fallbackSelect.value === defaultSelect.value) {
            fallbackSelect.value = '';
        }
    }

    if (defaultSelect && fallbackSelect) {
        syncFallbackOptions();
        defaultSelect.addEventListener('change', syncFallbackOptions);
    }

    document
        .querySelectorAll('.ai-provider-test-form')
        .forEach(function (form) {
            form.addEventListener('submit', function () {
                const button = form.querySelector('button[type="submit"]');

                if (!button || button.disabled) {
                    return;
                }

                button.disabled = true;
                button.textContent = 'Перевірка…';
                form.setAttribute('aria-busy', 'true');
            });
        });
})();
