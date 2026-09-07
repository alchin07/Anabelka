(function () {
    'use strict';

    const buttons = Array.from(
        document.querySelectorAll('[data-favorite-toggle]')
    );

    if (buttons.length === 0) {
        return;
    }

    const headerCount = document.getElementById('favorite-count');
    const script = document.getElementById('favorites-script');
    const endpoint = script?.dataset.endpoint || '/Anabelka/favorites/toggle';
    const addLabel = script?.dataset.addLabel || 'Добавить в избранное';
    const removeLabel = script?.dataset.removeLabel || 'Удалить из избранного';

    const updateButtons = function (productId, active, label) {
        buttons.forEach(function (button) {
            if ((button.dataset.productId || '') !== String(productId)) {
                return;
            }

            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
        });
    };

    const updateCount = function (count) {
        if (!headerCount) {
            return;
        }

        headerCount.textContent = String(Math.max(0, Number(count) || 0));
    };

    buttons.forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            if (button.disabled || button.classList.contains('is-loading')) {
                return;
            }

            const productId = Number(button.dataset.productId || 0);

            if (!productId) {
                return;
            }

            button.classList.add('is-loading');
            button.disabled = true;

            const body = new URLSearchParams();
            body.set('product_id', String(productId));

            fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Anabelka-Request': 'favorites',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: body.toString()
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return {
                            ok: response.ok,
                            data: data
                        };
                    });
                })
                .then(function (result) {
                    const data = result.data || {};

                    if (!result.ok || data.success !== true) {
                        if (data.gate_url) {
                            window.location.href = data.gate_url;
                        }
                        return;
                    }

                    const active = data.active === true;
                    const label = data.label || (active ? removeLabel : addLabel);

                    updateButtons(productId, active, label);
                    updateCount(data.count);

                    const removableCard = button.closest('[data-favorite-page-card]');
                    if (!active && removableCard) {
                        removableCard.remove();

                        const grid = document.querySelector('[data-favorite-page-grid]');
                        const empty = document.querySelector('[data-favorite-empty]');

                        if (grid && grid.children.length === 0 && empty) {
                            grid.hidden = true;
                            empty.hidden = false;
                        }
                    }
                })
                .catch(function () {
                    // Якщо мережевий запит не вдався, залишаємо поточний стан.
                })
                .finally(function () {
                    button.classList.remove('is-loading');
                    button.disabled = false;
                });
        });
    });
})();
