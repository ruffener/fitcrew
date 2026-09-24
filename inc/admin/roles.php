<?php
declare(strict_types=1);

/** Mutation service: a trusted Auth principal is mandatory; HTTP owns method/CSRF/confirmation. */
function fc_admin_change_role(PDO $pdo, array $principal, string $targetPublicId, string $action,
    string $expectedRole, bool $confirmed): void
{
    if ($pdo->inTransaction()) { throw new LogicException('Admin role operation owns its transaction.'); }
    $actor = $target = null;
    $newRole = ['make' => 'PLATFORM_ADMIN', 'remove' => 'USER'][$action] ?? null;
    $event = $action === 'make' ? 'ADMIN_ROLE_GRANTED' : ($action === 'remove' ? 'ADMIN_ROLE_REVOKED' : 'ADMIN_ROLE_REJECTED');
    $pdo->beginTransaction();
    try {
        // Resolve only the stable ID, then re-read all mutable truth under row locks.
        $s = $pdo->prepare('SELECT id FROM users WHERE public_id = ?');
        $s->execute([$targetPublicId]);
        $targetId = (int) $s->fetchColumn();
        $ids = array_unique([(int) $principal['user_id'], $targetId]);
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $row = fc_admin_lock_user($pdo, $id);
            if ($id === (int) $principal['user_id']) { $actor = $row; }
            if ($id === $targetId) { $target = $row; }
        }
        fc_admin_lock_authority($pdo, $principal, $actor, true);
        if (!$confirmed) { throw new FcAdminDenied('confirmation_required'); }
        if ($newRole === null) { throw new FcAdminDenied('invalid_action', 400); }
        if ($target === null) { throw new FcAdminDenied('target_missing', 404); }
        if ($target['platform_role_code'] === 'PLATFORM_SUPER_ADMIN') { throw new FcAdminDenied('super_admin_protected'); }
        $requiredOld = $action === 'make' ? 'USER' : 'PLATFORM_ADMIN';
        if ($expectedRole !== $requiredOld || $target['platform_role_code'] !== $expectedRole) {
            throw new FcAdminDenied('role_changed', 409);
        }
        if ($action === 'make' && $target['account_status'] !== 'ACTIVE') {
            throw new FcAdminDenied('target_inactive', 409);
        }
        $s = $pdo->prepare('UPDATE users SET platform_role_code = ? WHERE id = ? AND platform_role_code = ?');
        $s->execute([$newRole, (int) $target['id'], $requiredOld]);
        if ($s->rowCount() !== 1) { throw new RuntimeException('Admin role update failed.'); }
        fc_admin_audit($pdo, (int) $actor['id'], $event, 'SUCCESS', (int) $target['id'], $requiredOld, $newRole, 'role');
        $pdo->commit();
    } catch (FcAdminDenied $e) {
        try {
            fc_admin_audit($pdo, $actor === null ? null : (int) $actor['id'], $event, 'DENIED',
                $target === null ? null : (int) $target['id'], $target['platform_role_code'] ?? null, $newRole, 'role', $e->reason);
            $pdo->commit();
        } catch (Throwable $auditError) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $auditError;
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        // Best effort failure record AFTER rollback. Never report success if audit storage failed.
        try {
            fc_admin_audit($pdo, $actor === null ? null : (int) $actor['id'], $event, 'FAILURE',
                $target === null ? null : (int) $target['id'], $target['platform_role_code'] ?? null, $newRole, 'role', 'transaction_failed');
        } catch (Throwable $ignored) { /* The HTTP boundary returns a generic 503. */ }
        throw $e;
    }
}

function fc_admin_confirmation_issue(array $principal, array $target, string $action): string
{
    $now = time();
    $pending = $_SESSION['fitcrew_admin_confirmations'] ?? [];
    $pending = array_filter($pending, static fn ($v): bool => is_array($v) && ($v['expires'] ?? 0) > $now);
    // Bound storage and permit independent confirmation tabs.
    $pending = array_slice($pending, -9, null, true);
    $nonce = bin2hex(random_bytes(32));
    $pending[$nonce] = ['actor' => (int) $principal['user_id'], 'session' => (int) $principal['session_record_id'],
        'target' => $target['public_id'], 'action' => $action, 'old_role' => $target['platform_role_code'], 'expires' => $now + 300];
    $_SESSION['fitcrew_admin_confirmations'] = $pending;
    return $nonce;
}

function fc_admin_confirmation_consume(array $principal, string $nonce, string $target, string $action, string $oldRole): bool
{
    $v = $_SESSION['fitcrew_admin_confirmations'][$nonce] ?? null;
    unset($_SESSION['fitcrew_admin_confirmations'][$nonce]);
    return is_array($v) && ($v['expires'] ?? 0) > time()
        && $v['actor'] === (int) $principal['user_id'] && $v['session'] === (int) $principal['session_record_id']
        && $v['target'] === $target && $v['action'] === $action && $v['old_role'] === $oldRole;
}
