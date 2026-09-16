(() => {
    'use strict';

    const root = document.getElementById('fitcrew-google-auth');
    if (!root) {
        return;
    }

    const buttonHost = document.getElementById('fitcrew-google-button');
    const message = document.getElementById('fitcrew-google-message');

    const config = {
        clientId: root.dataset.clientId || '',
        transactionId: root.dataset.transactionId || '',
        state: root.dataset.state || '',
        nonce: root.dataset.nonce || '',
        csrfToken: root.dataset.csrfToken || '',
        endpoint: root.dataset.endpoint || '',
    };

    const setMessage = (text, isError = false) => {
        if (!message) {
            return;
        }
        message.textContent = text;
        message.classList.toggle('is-error', isError);
    };

    const submitCredential = async (response) => {
        if (!response || !response.credential || !response.state) {
            setMessage('Google sign-in did not return a usable credential. Please try again.', true);
            return;
        }

        setMessage('Completing secure sign-in…');
        const body = new FormData();
        body.set('credential', response.credential);
        body.set('state', response.state);
        body.set('transaction_id', config.transactionId);
        body.set('csrf_token', config.csrfToken);

        try {
            const result = await fetch(config.endpoint, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                },
            });
            const data = await result.json().catch(() => ({}));

            if (!result.ok || !data.ok || !data.redirect) {
                setMessage(data.message || 'Google sign-in could not be completed. Please try again.', true);
                return;
            }

            window.location.assign(data.redirect);
        } catch (error) {
            setMessage('Google sign-in is temporarily unavailable. Please try again.', true);
        }
    };

    const initialize = () => {
        if (!window.google || !window.google.accounts || !window.google.accounts.id) {
            return false;
        }

        window.google.accounts.id.initialize({
            client_id: config.clientId,
            callback: submitCredential,
            nonce: config.nonce,
            use_fedcm_for_button: true,
            button_auto_select: false,
            auto_select: false,
        });

        window.google.accounts.id.renderButton(buttonHost, {
            type: 'standard',
            theme: 'outline',
            size: 'large',
            text: 'continue_with',
            shape: 'rectangular',
            logo_alignment: 'left',
            width: Math.min(400, Math.max(260, Math.floor(root.getBoundingClientRect().width))),
            state: config.state,
        });

        setMessage('Google authentication only. Health-data access is separate.');
        return true;
    };

    let attempts = 0;
    const timer = window.setInterval(() => {
        attempts += 1;
        if (initialize()) {
            window.clearInterval(timer);
            return;
        }
        if (attempts >= 50) {
            window.clearInterval(timer);
            setMessage('Google sign-in could not be loaded. Please refresh and try again.', true);
        }
    }, 100);
})();
