/**
 * Показать универсальное сообщение.
 *
 * @param {string} text
 */
window.showMessage =
    function (text)
    {
        const message =
            document.getElementById(
                'site-message'
            );

        if (!message) {
            return;
        }


        message.textContent =
            text;

        message.classList.add(
            'show'
        );


        clearTimeout(
            window.siteMessageTimer
        );


        window.siteMessageTimer =
            setTimeout(
                () => {

                    message.classList.remove(
                        'show'
                    );

                },
                2200
            );
    };