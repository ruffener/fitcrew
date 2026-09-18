<?php
$isLinkAcknowledgement = $emailAckMode === 'link';
$ackMessage = $isLinkAcknowledgement
    ? FC_EMAIL_IDENTITY_LINK_REQUEST_MESSAGE : FC_EMAIL_MAGIC_LINK_REQUEST_MESSAGE;
?>
<div class="auth-email-ack-shade" aria-hidden="true"></div>
<dialog id="fitcrew-email-request-ack" class="auth-email-ack" open aria-modal="true" aria-labelledby="email-ack-title" aria-describedby="email-ack-message email-ack-help">
    <h2 id="email-ack-title">Check your email</h2>
    <p id="email-ack-message"><?= fc_e($ackMessage) ?></p>
    <p id="email-ack-help"><?php if ($isLinkAcknowledgement): ?>Open the confirmation in this same browser while still signed in.<?php else: ?>Use the newest sign-in email. Check your spam folder if it does not appear.<?php endif; ?></p>
    <form method="post" action="/auth/email/acknowledge.php">
        <input type="hidden" name="csrf_token" value="<?= fc_e(fc_csrf_token()) ?>">
        <button class="button button-primary" type="submit" autofocus>OK, I understand</button>
    </form>
</dialog>
