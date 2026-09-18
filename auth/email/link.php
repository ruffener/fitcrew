<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once fc_path('inc/auth/email_identity_link.php');

header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

if (!in_array(fc_request_method(), ['GET', 'POST'], true)) {
    http_response_code(405);
    exit('Method not allowed.');
}
if (!fc_is_logged_in()) {
    // This only requests a fixed destination after normal authentication.
    $_SESSION['fitcrew_email_link_setup_until'] = time() + 900;
    fc_redirect('/login.php');
}
unset($_SESSION['fitcrew_email_link_setup_until']);
$linkRecent = false;
try {
    fc_email_identity_link_session(fc_db(), session_id());
    $linkRecent = true;
} catch (DomainException) {
    // An older authenticated session may read the page, but may not add a method.
} catch (Throwable) {
    fc_log('error', 'Unable to prepare email sign-in setup.', ['reason' => 'email_link_setup_failed']);
}
if (fc_is_post()) {
    if (!fc_email_magic_link_request_origin_valid($_SERVER['HTTP_ORIGIN'] ?? null)
        || !fc_validate_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('This request could not be verified. Reload the setup page and try again.');
    }
    if ($linkRecent) {
        try {
            fc_email_identity_link_request(
                fc_db(), (string) ($_POST['email'] ?? ''), session_id(),
                fc_auth_browser_binding(), (string) ($_SERVER['REMOTE_ADDR'] ?? '')
            );
        } catch (Throwable) {
            fc_log('error', 'Email sign-in setup request failed.', ['reason' => 'email_link_request_failed']);
        }
        fc_email_request_require_acknowledgement('link');
        fc_redirect('/auth/email/link.php');
    }
}
$title = 'Add email sign-in';
$contentView = 'views/auth/email_link.php';
require fc_path('views/layouts/auth.php');
