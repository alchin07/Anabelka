(() => {
    'use strict';

    const endpoint = '/Anabelka/account/checkout-profile';

    const fillIfEmpty = (selector, value) => {
        const field = document.querySelector(selector);

        if (!field || typeof value !== 'string') {
            return;
        }

        if (field.value.trim() !== '' || value.trim() === '') {
            return;
        }

        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const applyProfile = (data) => {
        if (!data || !data.authenticated) {
            return;
        }

        fillIfEmpty('input[name="customer_name"]', data.name || '');
        fillIfEmpty('input[name="customer_email"]', data.email || '');
        fillIfEmpty('input[name="customer_phone"]', data.phone || '');

        const address = data.address || {};
        fillIfEmpty('input[name="delivery_country"]', address.country || '');
        fillIfEmpty('input[name="delivery_city"]', address.city || '');
        fillIfEmpty('input[name="delivery_address"]', address.address || '');
        fillIfEmpty('input[name="delivery_postcode"]', address.postcode || '');
    };

    const load = async () => {
        try {
            const response = await fetch(endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            applyProfile(data);
        } catch (error) {
            // Автопідстановка не повинна блокувати оформлення замовлення.
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', load, { once: true });
    } else {
        load();
    }
})();
