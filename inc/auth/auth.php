<?php

declare(strict_types=1);

function fc_current_user(): ?array
{
    static $resolved = false;
    static $current = null;

    if ($resolved) {
        return $current;
    }

    $resolved = true;
    $rawSessionId = session_id();
    if ($rawSessionId === '') {
        return null;
    }

    try {
        $policy = fc_session_timeout_policy();
        $current = fc_session_record_resolve_active(fc_db(), $rawSessionId, $policy['idle_seconds']);
    } catch (Throwable $e) {
        fc_log('warning', 'Unable to resolve authenticated FitCrew session.', [
            'reason' => 'session_resolution_failed',
        ]);
        $current = null;
    }

    return $current;
}

function fc_is_logged_in(): bool
{
    return fc_current_user() !== null;
}

function fc_require_login(): void
{
    if (fc_is_logged_in()) {
        return;
    }

    fc_flash('notice', 'Please sign in to continue to FitCrew Challenge.');
    fc_redirect('/login.php');
}
