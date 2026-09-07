(function () {
    'use strict';

    const script = document.getElementById('favorites-script');
    const endpoint = script?.dataset.endpoint || '/Anabelka/favorites/toggle';
    const stateEndpoint = script?.dataset.stateEndpoint || '/Anabelka/favorites/state';
    const addLabel = script?.dataset.addLabel || 'Добавить в избранное';
    const removeLabel = script?.dataset.removeLabel || 'Удалить из избранного';
    const headerCount = document.getElementById('favorite-count');

    const productSlugFromHref = function (href) {
        try {
            const url = new URL(href, window.location.origin);
            const marker = '/Anabelka/product/';
            const index = url.pathname.indexOf(marker);

            if (index === -1) {
                return '';
            }

            return decodeURIComponent(
                url.pathname.slice(index + marker.length).split('/')[0] || ''
            );
        } catch (error) {
            return '';
        }
    };

    const currentButtons = function () {
        return Array.from(
            document.querySelectorAll('[data-favorite-toggle]')
        );
    };

    const applyButtonState = function (button, active, label) {
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.dataset.label = label;
    };

    const updateButtons = function (productId, slug, active, label) {
        currentButtons().forEach(function (button) {
            const sameId = productId > 0
                && Number(button.dataset.productId || 0) === productId;
            const sameSlug = slug !== ''
                && (button.dataset.productSlug || '') === slug;

            if (!sameId && !sameSlug) {
                return;
            }

            if (productId > 0) {
                button.dataset.productId = String(productId);
            }

            if (slug !== '') {
                button.dataset.productSlug = slug;
            }

            applyButtonState(button, active, label);
        });
    };

    const updateCount = function (count) {
        if (headerCount) {
            headerCount.textContent = String(
                Math.max(0, Number(count) || 0)
            );
        }
    };

    const removeFavoritePageCard = function (button, active) {
        if (active) {
            return;
        }

        const card = button.closest('[data-favorite-page-card]');

        if (!card) {
            return;
        }

        card.remove();

        const grid = document.querySelector('[data-favorite-page-grid]');
        const empty = document.querySelector('[data-favorite-empty]');

        if (grid && grid.children.length === 0 && empty) {
            grid.hidden = true;
            empty.hidden = false;
        }
    };

    const bindButton = function (button) {
        if (button.dataset.favoriteBound === '1') {
            return;
        }

        button.dataset.favoriteBound = '1';

        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            if (
                button.disabled
                || button.classList.contains('is-loading')
            ) {
                return;
            }

            const productId = Number(button.dataset.productId || 0);
            const slug = button.dataset.productSlug || '';

            if (!productId && slug === '') {
                return;
            }

            button.classList.add('is-loading');
            button.disabled = true;

            const body = new URLSearchParams();

            if (productId > 0) {
                body.set('product_id', String(productId));
            } else {
                body.set('slug', slug);
            }

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
                    const resultId = Number(data.product_id || productId || 0);
                    const resultSlug = data.slug || slug;
                    const label = data.label
                        || (active ? removeLabel : addLabel);

                    updateButtons(
                        resultId,
                        resultSlug,
                        active,
                        label
                    );
                    updateCount(data.count);
                    removeFavoritePageCard(button, active);
                })
                .catch(function () {
                    // Залишаємо поточний стан, якщо мережа недоступна.
                })
                .finally(function () {
                    button.classList.remove('is-loading');
                    button.disabled = false;
                });
        });
    };

    const makeButton = function (productId, slug, active, detail) {
        const button = document.createElement('button');
        const label = active ? removeLabel : addLabel;

        button.type = 'button';
        button.className = 'favorite-toggle' + (active ? ' is-active' : '');
        button.dataset.favoriteToggle = '';

        if (productId > 0) {
            button.dataset.productId = String(productId);
        }

        if (slug !== '') {
            button.dataset.productSlug = slug;
        }

        if (detail) {
            button.classList.add('favorite-detail-toggle');
        }

        applyButtonState(button, active, label);
        bindButton(button);

        return button;
    };

    const wrapCardLink = function (link, activeSlugs) {
        if (
            !link.parentNode
            || link.closest('.favorite-card-host')
        ) {
            return;
        }

        const slug = productSlugFromHref(link.href);

        if (slug === '') {
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'favorite-card-host';
        link.parentNode.insertBefore(wrapper, link);
        wrapper.appendChild(link);
        wrapper.appendChild(
            makeButton(0, slug, activeSlugs.has(slug), false)
        );
    };

    const addAutomaticCardButtons = function (activeSlugs) {
        document.querySelectorAll(
            'a.home-product-card[href*="/Anabelka/product/"], '
            + 'a.search-product-card[href*="/Anabelka/product/"]'
        ).forEach(function (link) {
            wrapCardLink(link, activeSlugs);
        });
    };

    const addProductDetailButton = function (activeIds, activeSlugs) {
        const cartForm = document.getElementById('cart-form');

        if (!cartForm) {
            return;
        }

        const card = cartForm.closest('.product-card');
        const productIdInput = cartForm.querySelector('input[name="product_id"]');
        const productId = Number(productIdInput?.value || 0);
        const slug = productSlugFromHref(window.location.href);

        if (!card || (!productId && slug === '')) {
            return;
        }

        if (card.querySelector('[data-favorite-toggle]')) {
            return;
        }

        card.classList.add('favorite-product-detail');
        const title = card.querySelector('h2');
        const row = document.createElement('div');
        row.className = 'favorite-detail-row';
        const active = productId > 0
            ? activeIds.has(productId)
            : activeSlugs.has(slug);
        row.appendChild(makeButton(productId, slug, active, true));

        if (title) {
            title.insertAdjacentElement('afterend', row);
        } else {
            card.insertBefore(row, card.firstChild);
        }
    };

    const syncExistingButtons = function (activeIds, activeSlugs) {
        currentButtons().forEach(function (button) {
            const id = Number(button.dataset.productId || 0);
            const slug = button.dataset.productSlug || '';
            const active = id > 0
                ? activeIds.has(id)
                : activeSlugs.has(slug);
            applyButtonState(
                button,
                active,
                active ? removeLabel : addLabel
            );
            bindButton(button);
        });
    };

    const initialize = function () {
        fetch(stateEndpoint, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Favorites state request failed');
                }

                return response.json();
            })
            .then(function (data) {
                if (!data || data.success !== true) {
                    return;
                }

                const items = Array.isArray(data.items) ? data.items : [];
                const activeIds = new Set();
                const activeSlugs = new Set();

                items.forEach(function (item) {
                    const id = Number(item.id || 0);
                    const slug = String(item.slug || '');

                    if (id > 0) {
                        activeIds.add(id);
                    }

                    if (slug !== '') {
                        activeSlugs.add(slug);
                    }
                });

                updateCount(data.count);
                syncExistingButtons(activeIds, activeSlugs);
                addAutomaticCardButtons(activeSlugs);
                addProductDetailButton(activeIds, activeSlugs);
            })
            .catch(function () {
                currentButtons().forEach(bindButton);
            });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
