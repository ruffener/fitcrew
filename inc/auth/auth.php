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

/** Self-only presentation; never use this label as authentication authority. */
function fc_current_account_email(PDO $pdo): ?string
{
    $current = fc_current_user();
    if ($current === null) return null;
    $query = $pdo->prepare(
        'SELECT email_canonical FROM user_contact_emails ' .
        'WHERE user_id = :user_id AND verification_status = \'VERIFIED\' ' .
        'AND removed_at IS NULL AND verified_email_canonical = email_canonical ' .
        'ORDER BY is_primary_for_contact DESC, id ASC LIMIT 1'
    );
    $query->execute([':user_id' => (int) $current['user_id']]);
    $email = $query->fetchColumn();
    return $email === false ? null : (string) $email;
}
