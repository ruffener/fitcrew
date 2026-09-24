<?php
declare(strict_types=1);

final class FcAdminDenied extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 403)
    {
        parent::__construct($reason);
    }
}

function fc_admin_is_admin(?array $user): bool
{
    return $user !== null && ($user['account_status'] ?? '') === 'ACTIVE'
        && in_array($user['platform_role_code'] ?? '', ['PLATFORM_ADMIN', 'PLATFORM_SUPER_ADMIN'], true);
}

function fc_admin_is_super(?array $user): bool
{
    return fc_admin_is_admin($user) && $user['platform_role_code'] === 'PLATFORM_SUPER_ADMIN';
}

/** Current locking read; callers own the transaction. No session or identity mutation. */
function fc_admin_lock_user(PDO $pdo, int $id): ?array
{
    $s = $pdo->prepare('SELECT id, public_id, display_name, account_status, platform_role_code FROM users WHERE id = ? FOR UPDATE');
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Consume the Auth-resolved principal, then fence its durable authority under locks.
 * Lock order: users (numeric order for role operations), identity, session.
 * Never accepts a principal or session identifier from request input.
 */
function fc_admin_lock_authority(PDO $pdo, array $principal, ?array $actor, bool $superOnly): array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Admin authority requires a transaction.');
    }
    if ($actor === null || (int) $actor['id'] !== (int) ($principal['user_id'] ?? 0)
        || !fc_admin_is_admin($actor) || ($superOnly && !fc_admin_is_super($actor))) {
        throw new FcAdminDenied('role_required');
    }
    $s = $pdo->prepare('SELECT identity_status FROM user_auth_identities WHERE id = ? AND user_id = ? FOR UPDATE');
    $s->execute([(int) ($principal['auth_identity_id'] ?? 0), (int) $actor['id']]);
    if ($s->fetchColumn() !== 'ACTIVE') {
        throw new FcAdminDenied('identity_inactive');
    }
    $s = $pdo->prepare('SELECT id, revoked_at, idle_expires_at, absolute_expires_at FROM user_sessions '
        . 'WHERE id = ? AND user_id = ? AND auth_identity_id = ? FOR UPDATE');
    $s->execute([(int) ($principal['session_record_id'] ?? 0), (int) $actor['id'], (int) $principal['auth_identity_id']]);
    $session = $s->fetch(PDO::FETCH_ASSOC);
    // Obtain time after any lock wait; a pre-wait NOW() must not extend authority.
    $now = (string) $pdo->query('SELECT CURRENT_TIMESTAMP(6)')->fetchColumn();
    if ($session === false || $session['revoked_at'] !== null
        || $session['idle_expires_at'] <= $now || $session['absolute_expires_at'] <= $now) {
        throw new FcAdminDenied('session_inactive');
    }
    return $actor;
}

function fc_admin_enter(PDO $pdo, array $principal, string $route, bool $superOnly): array
{
    $pdo->beginTransaction();
    $actor = null;
    try {
        $actor = fc_admin_lock_user($pdo, (int) $principal['user_id']);
        $actor = fc_admin_lock_authority($pdo, $principal, $actor, $superOnly);
        fc_admin_audit($pdo, (int) $actor['id'], 'ADMIN_ENTRY', 'SUCCESS', null, null, null, $route);
        $pdo->commit();
        return $actor;
    } catch (FcAdminDenied $e) {
        try {
            fc_admin_audit($pdo, $actor === null ? null : (int) $actor['id'], 'ADMIN_ENTRY', 'DENIED', null, null, null, $route, $e->reason);
            $pdo->commit();
        } catch (Throwable $auditError) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $auditError;
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
