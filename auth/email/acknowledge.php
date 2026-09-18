<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once fc_path('inc/auth/email_magic_link.php');

header('Cache-Control: no-store, private');
if (!fc_is_post()) {
    http_response_code(405);
    exit('Method not allowed.');
}
if (!fc_email_magic_link_completion_origin_valid($_SERVER['HTTP_ORIGIN'] ?? null)
    || !fc_validate_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('This acknowledgement could not be verified. Reload the page and try again.');
}
unset($_SESSION['fitcrew_email_request_ack']);
fc_redirect('/login.php');
