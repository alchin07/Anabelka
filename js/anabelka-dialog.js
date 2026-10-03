(function () {
    'use strict';

    if (window.AnabelkaDialog) {
        return;
    }

    let host = null;
    let panel = null;
    let titleNode = null;
    let messageNode = null;
    let cancelButton = null;
    let confirmButton = null;
    let activeResolver = null;
    let previousFocus = null;

    function ensureDialog()
    {
        if (host) {
            return host;
        }

        host = document.createElement('div');
        host.className = 'anabelka-dialog';
        host.hidden = true;

        const backdrop = document.createElement('button');
        backdrop.type = 'button';
        backdrop.className = 'anabelka-dialog-backdrop';
        backdrop.setAttribute('aria-label', 'Закрити діалог');
        backdrop.tabIndex = -1;

        panel = document.createElement('section');
        panel.className = 'anabelka-dialog-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('aria-labelledby', 'anabelka-dialog-title');
        panel.setAttribute('aria-describedby', 'anabelka-dialog-message');
        panel.tabIndex = -1;

        titleNode = document.createElement('h2');
        titleNode.id = 'anabelka-dialog-title';
        titleNode.className = 'anabelka-dialog-title';

        messageNode = document.createElement('p');
        messageNode.id = 'anabelka-dialog-message';
        messageNode.className = 'anabelka-dialog-message';

        const actions = document.createElement('div');
        actions.className = 'anabelka-dialog-actions';

        cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'anabelka-dialog-button is-cancel';

        confirmButton = document.createElement('button');
        confirmButton.type = 'button';
        confirmButton.className = 'anabelka-dialog-button is-confirm';

        actions.appendChild(cancelButton);
        actions.appendChild(confirmButton);
        panel.appendChild(titleNode);
        panel.appendChild(messageNode);
        panel.appendChild(actions);
        host.appendChild(backdrop);
        host.appendChild(panel);
        document.body.appendChild(host);

        backdrop.addEventListener('click', function () {
            resolve(false);
        });
        cancelButton.addEventListener('click', function () {
            resolve(false);
        });
        confirmButton.addEventListener('click', function () {
            resolve(true);
        });

        return host;
    }

    function focusableButtons()
    {
        if (!panel) {
            return [];
        }

        return Array.from(
            panel.querySelectorAll('button:not([disabled])')
        );
    }

    function handleKeydown(event)
    {
        if (!host || host.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            resolve(false);
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const buttons = focusableButtons();

        if (buttons.length === 0) {
            event.preventDefault();
            panel.focus();
            return;
        }

        const first = buttons[0];
        const last = buttons[buttons.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function resolve(result)
    {
        if (!activeResolver) {
            return;
        }

        const resolver = activeResolver;
        activeResolver = null;

        host.hidden = true;
        document.body.classList.remove('anabelka-dialog-open');
        document.removeEventListener('keydown', handleKeydown, true);

        if (
            window.AnabelkaAdminBack
            && typeof window.AnabelkaAdminBack.syncNow === 'function'
        ) {
            window.AnabelkaAdminBack.syncNow();
        }

        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }

        previousFocus = null;
        resolver(Boolean(result));
    }

    function confirm(options)
    {
        const settings = options && typeof options === 'object'
            ? options
            : {};

        ensureDialog();

        if (activeResolver) {
            resolve(false);
        }

        previousFocus = document.activeElement;
        titleNode.textContent = String(
            settings.title || 'Підтвердження'
        );
        messageNode.textContent = String(
            settings.message || 'Підтвердити цю дію?'
        );
        cancelButton.textContent = String(
            settings.cancelText || 'Скасувати'
        );
        confirmButton.textContent = String(
            settings.confirmText || 'Підтвердити'
        );
        confirmButton.classList.toggle(
            'is-danger',
            settings.danger === true
        );

        host.hidden = false;
        document.body.classList.add('anabelka-dialog-open');
        document.addEventListener('keydown', handleKeydown, true);

        if (
            window.AnabelkaAdminBack
            && typeof window.AnabelkaAdminBack.syncNow === 'function'
        ) {
            window.AnabelkaAdminBack.syncNow();
        }

        window.requestAnimationFrame(function () {
            cancelButton.focus();
        });

        return new Promise(function (resolvePromise) {
            activeResolver = resolvePromise;
        });
    }

    function formOptions(form)
    {
        return {
            title: form.dataset.anabelkaConfirmTitle
                || 'Підтвердження',
            message: form.dataset.anabelkaConfirm
                || 'Підтвердити цю дію?',
            confirmText: form.dataset.anabelkaConfirmConfirmText
                || 'Підтвердити',
            cancelText: form.dataset.anabelkaConfirmCancelText
                || 'Скасувати',
            danger: form.dataset.anabelkaConfirmDanger === '1'
        };
    }

    document.addEventListener('submit', function (event) {
        const form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (!form.hasAttribute('data-anabelka-confirm')) {
            return;
        }

        if (form.dataset.anabelkaConfirmed === '1') {
            delete form.dataset.anabelkaConfirmed;
            return;
        }

        event.preventDefault();
        const submitter = event.submitter || null;

        confirm(formOptions(form)).then(function (accepted) {
            if (!accepted) {
                return;
            }

            form.dataset.anabelkaConfirmed = '1';

            if (typeof form.requestSubmit === 'function') {
                if (submitter) {
                    form.requestSubmit(submitter);
                } else {
                    form.requestSubmit();
                }
                return;
            }

            delete form.dataset.anabelkaConfirmed;
            HTMLFormElement.prototype.submit.call(form);
        });
    });

    if (
        window.AnabelkaAdminBack
        && typeof window.AnabelkaAdminBack.register === 'function'
    ) {
        window.AnabelkaAdminBack.register({
            key: 'anabelka-dialog',
            priority: 120,
            isActive: function () {
                return Boolean(
                    host
                    && !host.hidden
                    && activeResolver
                );
            },
            close: function () {
                resolve(false);
            }
        });
    }

    window.AnabelkaDialog = {
        confirm: confirm
    };
}());
