<?php
$emailRequestNeedsRetry = ($_SESSION['fitcrew_email_request_ack'] ?? null) === 'retry';
?>
<div class="auth-email-ack-shade" aria-hidden="true"></div>
<dialog id="fitcrew-email-request-ack" class="auth-email-ack" open aria-modal="true" aria-labelledby="email-ack-title" aria-describedby="email-ack-message email-ack-help">
    <h2 id="email-ack-title"><?= $emailRequestNeedsRetry ? 'Please try again' : 'Check your email' ?></h2>
    <p id="email-ack-message" class="fc-notice <?= $emailRequestNeedsRetry ? 'fc-notice-error' : 'fc-notice-info' ?>"><?= fc_e($emailRequestNeedsRetry ? FC_EMAIL_MAGIC_LINK_RETRY_MESSAGE : FC_EMAIL_MAGIC_LINK_REQUEST_MESSAGE) ?></p>
    <p id="email-ack-help"><?= $emailRequestNeedsRetry ? 'No sign-in email was requested. Select OK, then enter your email again.' : 'Use the newest sign-in email. Check your spam folder if it does not appear.' ?></p>
    <form method="post" action="/auth/email/acknowledge.php">
        <input type="hidden" name="csrf_token" value="<?= fc_e(fc_csrf_token()) ?>">
        <button class="button button-primary" type="submit" autofocus>OK, I understand</button>
    </form>
</dialog>
