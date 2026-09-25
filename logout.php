<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

header('Cache-Control: no-store, private');

if (!fc_is_post()) {
    fc_redirect('/');
}

$csrfToken = $_POST['csrf_token'] ?? null;
if (!is_string($csrfToken) || !fc_validate_csrf($csrfToken)) {
    // An older tab can retain a token from before logout or account switching.
    // Never treat an unverified request as permission to sign out an active
    // session, including a different account now open in another browser tab.
    if (fc_current_user() === null) {
        fc_flash('notice', 'Please sign in again to continue.');
        header('Location: /login.php', true, 303);
    } else {
        fc_flash('notice', 'We could not verify your sign-out request. Please select Sign Out again.');
        header('Location: /app.php', true, 303);
    }
    exit;
}

$currentUser = fc_current_user();
$rawSessionId = session_id();

if ($rawSessionId !== '') {
    try {
        $pdo = fc_db();
        $revoked = fc_session_revoke($pdo, $rawSessionId, 'user_logout');
        if ($revoked && $currentUser !== null) {
            fc_audit_event_write($pdo, [
                'actor_user_id' => (int) $currentUser['user_id'],
                'event_type' => 'SESSION_REVOKED_LOGOUT',
                'target_type' => 'SESSION',
                'target_id' => (string) ($currentUser['session_record_id'] ?? ''),
                'outcome' => 'SUCCESS',
                'metadata' => ['provider' => (string) ($currentUser['provider_key'] ?? 'UNKNOWN')],
                'raw_client_evidence' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        }
    } catch (Throwable) {
        // Local session destruction still proceeds; logout must fail closed.
    }
}

fc_destroy_local_session();
fc_redirect('/');
