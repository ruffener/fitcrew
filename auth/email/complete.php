<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once fc_path('inc/auth/email_magic_link.php');

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

function fc_email_magic_link_complete_rejection(string $reason): never
{
    try {
        fc_email_magic_link_audit_rejection(fc_db(), $reason);
    } catch (Throwable) {
    }
    fc_flash('error', 'This email sign-in link is invalid, expired, replaced, or already used.');
    fc_redirect('/login.php');
}

if (!fc_is_post()) {
    http_response_code(405);
    exit('Method not allowed.');
}

$networkEvidence = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
try {
    $attempt = fc_rate_limit_consume(
        fc_db(),
        'auth.email_magic.complete.invalid.network',
        $networkEvidence !== '' ? $networkEvidence : 'browser:' . fc_auth_browser_binding(),
        20,
        900
    );
    if (!$attempt['allowed']) {
        fc_email_magic_link_complete_rejection('rate_limited');
    }
} catch (Throwable) {
    fc_email_magic_link_complete_rejection('unexpected_failure');
}

if (!fc_email_magic_link_request_origin_valid($_SERVER['HTTP_ORIGIN'] ?? null)) {
    fc_email_magic_link_complete_rejection('origin_failed');
}
if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) {
    fc_email_magic_link_complete_rejection('csrf_failed');
}

$rawToken = trim((string) ($_POST['token'] ?? ''));
$browserBinding = fc_auth_browser_binding();
if (!fc_email_magic_link_token_valid_shape($rawToken)) {
    fc_email_magic_link_complete_rejection('link_failed');
}

try {
    if (!session_regenerate_id(true) || session_id() === '') {
        throw new RuntimeException('Unable to establish a fresh local FitCrew session.');
    }
    $result = fc_email_magic_link_complete(
        fc_db(),
        $rawToken,
        $browserBinding,
        session_id(),
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        $networkEvidence
    );
    fc_email_magic_link_apply_committed_arrival_context($result);
    fc_redirect((string) $result['destination']);
} catch (DomainException $error) {
    $reason = match ($error->getMessage()) {
        'fitcrew_account_access_denied' => 'account_denied',
        'prelaunch_new_account_denied' => 'prelaunch_denied',
        'prelaunch_invitation_denied',
        'prelaunch_invitation_admission_claimed',
        'invitation_continuation_invalid',
        'invitation_continuation_product_invalid',
        'invitation_continuation_already_used' => 'invitation_failed',
        default => 'link_failed',
    };
    if ($reason === 'invitation_failed') {
        fc_auth_crew_invitation_continuation_clear_session();
    }
    fc_email_magic_link_complete_rejection($reason);
} catch (Throwable) {
    fc_log('error', 'Email authentication failed unexpectedly.', [
        'reason' => 'email_magic_link_completion_failure',
    ]);
    fc_email_magic_link_complete_rejection('unexpected_failure');
}
