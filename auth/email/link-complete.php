<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once fc_path('inc/auth/email_identity_link.php');

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

if (!fc_is_post()) {
    http_response_code(405);
    exit('Method not allowed.');
}
try {
    if (!fc_email_magic_link_completion_origin_valid($_SERVER['HTTP_ORIGIN'] ?? null)
        || !fc_validate_csrf($_POST['csrf_token'] ?? null)) {
        throw new DomainException('email_identity_link_invalid');
    }
    $limit = fc_rate_limit_consume(
        fc_db(), 'auth.email_magic.complete.invalid.network',
        (string) ($_SERVER['REMOTE_ADDR'] ?? '') ?: 'browser:' . fc_auth_browser_binding(), 20, 900
    );
    if (!$limit['allowed']) throw new DomainException('email_identity_link_invalid');
    fc_email_identity_link_complete(
        fc_db(), trim((string) ($_POST['token'] ?? '')), session_id(), fc_auth_browser_binding()
    );
    fc_flash('success', 'Email sign-in is ready for this account. Next time, you can request an email sign-in link. Your existing sign-in method still works.');
} catch (DomainException $error) {
    $message = match ($error->getMessage()) {
        'email_link_recent_signin_required' => 'Use the same browser where you requested this confirmation, while signed in. If your sign-in is older than 10 minutes, sign in again and request a new confirmation.',
        'account_reconciliation_required', 'canonical_verified_email_conflict',
        'email_identity_link_unavailable' => 'This email cannot be added safely to the current account. Account reconciliation is required; no sign-in method or email ownership was changed.',
        default => 'This confirmation is invalid, expired, replaced, already used, or belongs to a different signed-in session. Return to setup and request a new confirmation.',
    };
    fc_flash('error', $message);
    try {
        fc_email_magic_link_audit_rejection(fc_db(), 'email_identity_link_denied');
    } catch (Throwable) {
    }
} catch (Throwable) {
    fc_log('error', 'Email sign-in setup failed.', ['reason' => 'email_link_completion_failed']);
    fc_flash('error', 'Email sign-in could not be added. Please return to setup and try again.');
}
fc_redirect('/auth/email/link.php');
