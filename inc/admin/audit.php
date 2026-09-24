<?php
declare(strict_types=1);

/** Explicit metadata allowlist. Request text, provider data and secrets never enter it. */
function fc_admin_audit(PDO $pdo, ?int $actorId, string $action, string $result, ?int $targetId = null,
    ?string $oldRole = null, ?string $newRole = null, ?string $route = null, ?string $reason = null): void
{
    fc_audit_event_write($pdo, [
        'actor_user_id' => $actorId,
        'event_type' => $action,
        'target_type' => $targetId === null ? 'ADMIN_SURFACE' : 'USER',
        'target_id' => $targetId === null ? $route : (string) $targetId,
        'outcome' => $result,
        'metadata' => ['old_role' => $oldRole, 'new_role' => $newRole, 'route' => $route, 'reason' => $reason],
    ]);
}
