(() => {
    'use strict';

    const root = document.getElementById('fitcrew-google-auth');
    if (!root) return;

    const buttonHost = document.getElementById('fitcrew-google-button');
    const message = document.getElementById('fitcrew-google-message');
    const config = {
        clientId: root.dataset.clientId || '',
        transactionId: root.dataset.transactionId || '',
        state: root.dataset.state || '',
        nonce: root.dataset.nonce || '',
        expiresAt: root.dataset.expiresAt || '',
        csrfToken: root.dataset.csrfToken || '',
        endpoint: root.dataset.endpoint || '',
        refreshEndpoint: root.dataset.refreshEndpoint || '',
    };
    let refreshTimer = null;
    let refreshInFlight = false;

    const setMessage = (text, isError = false) => {
        if (!message) return;
        message.textContent = text;
        message.classList.toggle('is-error', isError);
    };

    const transactionExpiryMs = () => {
        const iso = config.expiresAt
            .replace(' ', 'T')
            .replace(/(\.\d{3})\d+$/, '$1') + 'Z';
        const parsed = Date.parse(iso);
        return Number.isFinite(parsed) ? parsed : 0;
    };

    const applyGoogleConfig = (next) => {
        if (!next || !next.transaction_id || !next.state || !next.nonce || !next.expires_at) {
            throw new Error('The refreshed Google transaction is incomplete.');
        }
        config.clientId = next.client_id || config.clientId;
        config.transactionId = next.transaction_id;
        config.state = next.state;
        config.nonce = next.nonce;
        config.expiresAt = next.expires_at;
    };

    const scheduleRefresh = () => {
        if (refreshTimer !== null) window.clearTimeout(refreshTimer);
        const delay = Math.max(0, transactionExpiryMs() - Date.now() - 60000);
        refreshTimer = window.setTimeout(() => refreshTransaction(), Math.min(delay, 2147483647));
    };

    const renderButton = () => {
        if (!window.google || !window.google.accounts || !window.google.accounts.id) return false;

        window.google.accounts.id.initialize({
            client_id: config.clientId,
            callback: submitCredential,
            nonce: config.nonce,
            use_fedcm_for_button: true,
            button_auto_select: false,
            auto_select: false,
        });
        buttonHost.replaceChildren();
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
        scheduleRefresh();
        return true;
    };

    const refreshTransaction = async () => {
        if (refreshInFlight || !config.refreshEndpoint) return;
        refreshInFlight = true;
        setMessage('Refreshing secure Google sign-in…');
        const body = new FormData();
        body.set('transaction_id', config.transactionId);
        body.set('state', config.state);
        body.set('csrf_token', config.csrfToken);

        try {
            const result = await fetch(config.refreshEndpoint, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
            });
            const data = await result.json().catch(() => ({}));
            if (!result.ok || !data.ok || !data.google) {
                setMessage(data.message || 'Google sign-in could not be refreshed. Please reload the page and try again.', true);
                return;
            }
            applyGoogleConfig(data.google);
            renderButton();
            setMessage(data.message || 'Your sign-in page was refreshed. Please continue with Google again.');
        } catch (error) {
            setMessage('Google sign-in could not be refreshed. Please reload the page and try again.', true);
        } finally {
            refreshInFlight = false;
        }
    };

    async function submitCredential(response) {
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
                headers: {'Accept': 'application/json'},
            });
            const data = await result.json().catch(() => ({}));
            if (data.refresh_required && data.google) {
                applyGoogleConfig(data.google);
                renderButton();
                setMessage(data.message || 'Your sign-in page was refreshed. Please continue with Google again.');
                return;
            }
            if (!result.ok || !data.ok || !data.redirect) {
                setMessage(data.message || 'Google sign-in could not be completed. Please try again.', true);
                return;
            }
            window.location.assign(data.redirect);
        } catch (error) {
            setMessage('Google sign-in is temporarily unavailable. Please try again.', true);
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && transactionExpiryMs() - Date.now() <= 90000) refreshTransaction();
    });

    let attempts = 0;
    const timer = window.setInterval(() => {
        attempts += 1;
        if (renderButton()) {
            window.clearInterval(timer);
            setMessage('Google authentication only. Health-data access is separate.');
            return;
        }
        if (attempts >= 50) {
            window.clearInterval(timer);
            setMessage('Google sign-in could not be loaded. Please refresh and try again.', true);
        }
    }, 100);
})();
