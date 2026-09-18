<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
if (!fc_is_post()) { http_response_code(405); exit('Method not allowed.'); }
if (!fc_email_magic_link_completion_origin_valid($_SERVER['HTTP_ORIGIN'] ?? null)
    || !fc_validate_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('This account switch could not be verified. Reload the invitation and try again.');
}
$pdo = null;
try {
    $pdo = fc_db();
    $binding = bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    $prepared = fc_auth_crew_invitation_prepare_account_switch($pdo, $binding);
    $pdo->commit();
    if (!session_regenerate_id(true)) {
        fc_destroy_local_session();
        throw new RuntimeException('Unable to renew the browser session.');
    }
    // Carry only new invitation authority into the signed-out session.
    $_SESSION = [
        'fitcrew_auth_browser_binding' => $binding,
        FC_AUTH_CREW_INVITATION_SESSION_KEY => $prepared['public_id'],
    ];
    fc_flash('notice', 'You’re signed out. Choose the account you want to use for this invitation.');
    fc_redirect('/login.php');
} catch (Throwable) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fc_flash('error', 'This invitation could not be continued. Open the latest invitation email and try again.');
    fc_redirect('/crew-invite.php');
}
