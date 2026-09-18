(() => {
    'use strict';
    const dialog = document.getElementById('fitcrew-email-request-ack');
    if (!dialog) return;
    // Acknowledge through the CSRF-protected form. No Escape/backdrop dismissal.
    dialog.addEventListener('cancel', (event) => event.preventDefault());
    if (typeof dialog.showModal === 'function') {
        dialog.close();
        dialog.showModal();
        document.querySelector('.auth-email-ack-shade').hidden = true;
    }
    dialog.querySelector('button[type="submit"]').focus();
})();
