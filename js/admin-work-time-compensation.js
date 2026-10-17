(function () {
    'use strict';

    const forms = document.querySelectorAll(
        '[data-work-compensation-form]'
    );

    if (!forms.length) {
        return;
    }

    forms.forEach(function (form) {
        const payout = form.querySelector('[data-work-payout-type]');
        const oneTimeFields = form.querySelector(
            '[data-work-one-time-fields]'
        );

        if (!payout || !oneTimeFields) {
            return;
        }

        function sync()
        {
            const isOneTime = payout.value === 'one_time';
            oneTimeFields.hidden = !isOneTime;

            oneTimeFields.querySelectorAll('input[type="date"]')
                .forEach(function (input) {
                    input.required = isOneTime;
                });
        }

        payout.addEventListener('change', sync);
        sync();
    });
}());
