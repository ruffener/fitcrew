<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

if (!fc_is_post()) {
    fc_redirect('/');
}

if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) {
    fc_response_code(403);
    exit('Forbidden');
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
