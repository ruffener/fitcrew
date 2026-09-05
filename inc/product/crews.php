<?php

declare(strict_types=1);

/** @return array{id:int,public_id:string} */
function fc_crew_create(PDO $pdo, int $ownerUserId, string $displayName, ?string $description = null): array
{
    $displayName = trim($displayName);
    $description = $description !== null ? trim($description) : null;
    if ($displayName === '' || strlen($displayName) > 120) {
        throw new InvalidArgumentException('Crew name is required and must be 120 characters or fewer.');
    }
    if ($description !== null && $description !== '' && strlen($description) > 500) {
        throw new InvalidArgumentException('Crew description must be 500 characters or fewer.');
    }

    return fc_product_atomic($pdo, function () use ($pdo, $ownerUserId, $displayName, $description): array {
        if (fc_user_find_by_id($pdo, $ownerUserId, true) === null) {
            throw new InvalidArgumentException('Crew Owner user does not exist.');
        }

        $publicId = fc_new_public_id();
        $insert = $pdo->prepare(
            'INSERT INTO crews (public_id, display_name, description, owner_user_id) ' .
            'VALUES (:public_id, :display_name, :description, :owner_user_id)'
        );
        $insert->execute([
            ':public_id' => $publicId,
            ':display_name' => $displayName,
            ':description' => $description === '' ? null : $description,
            ':owner_user_id' => $ownerUserId,
        ]);
        $crewId = (int) $pdo->lastInsertId();

        $membership = $pdo->prepare(
            'INSERT INTO crew_memberships (crew_id, user_id, role_code, membership_status) ' .
            'VALUES (:crew_id, :user_id, \'OWNER\', \'ACTIVE\')'
        );
        $membership->execute([':crew_id' => $crewId, ':user_id' => $ownerUserId]);

        fc_audit_event_write($pdo, [
            'actor_user_id' => $ownerUserId,
            'event_type' => 'CREW_CREATED',
            'target_type' => 'CREW',
            'target_id' => $publicId,
            'outcome' => 'SUCCESS',
            'group_id' => $crewId,
        ]);

        return ['id' => $crewId, 'public_id' => $publicId];
    });
}

/** @return list<array<string,mixed>> */
function fc_crews_for_user(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT c.*, m.role_code AS membership_role, m.joined_at, ' .
        ' (SELECT COUNT(*) FROM crew_memberships cm WHERE cm.crew_id = c.id AND cm.membership_status = \'ACTIVE\') AS member_count ' .
        'FROM crew_memberships m JOIN crews c ON c.id = m.crew_id ' .
        'WHERE m.user_id = :user_id AND m.membership_status = \'ACTIVE\' AND c.crew_status = \'ACTIVE\' ' .
        'ORDER BY c.created_at DESC, c.id DESC'
    );
    $statement->execute([':user_id' => $userId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string,mixed> */
function fc_crew_require_public(PDO $pdo, int $userId, string $publicId): array
{
    $statement = $pdo->prepare('SELECT id FROM crews WHERE public_id = :public_id LIMIT 1');
    $statement->execute([':public_id' => trim($publicId)]);
    $crewId = $statement->fetchColumn();
    if ($crewId === false) {
        fc_product_access_denied('Crew access denied.');
    }
    return fc_crew_require_member($pdo, $userId, (int) $crewId);
}

/** @return list<array<string,mixed>> */
function fc_crew_memberships(PDO $pdo, int $requestUserId, int $crewId): array
{
    fc_crew_require_member($pdo, $requestUserId, $crewId);
    $statement = $pdo->prepare(
        'SELECT m.id, m.user_id, m.role_code, m.membership_status, m.joined_at, m.left_at, m.removed_at, ' .
        ' u.display_name, u.public_id AS user_public_id ' .
        'FROM crew_memberships m JOIN users u ON u.id = m.user_id ' .
        'WHERE m.crew_id = :crew_id ' .
        'ORDER BY CASE m.role_code WHEN \'OWNER\' THEN 0 ELSE 1 END, m.joined_at, m.id'
    );
    $statement->execute([':crew_id' => $crewId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Foundation service for a future invitation acceptance path.
 * It is intentionally not exposed as a Wave 1 participant-facing form.
 */
function fc_crew_membership_add_existing(PDO $pdo, int $actorUserId, int $crewId, int $memberUserId): void
{
    fc_crew_require_owner($pdo, $actorUserId, $crewId);
    if (fc_user_find_by_id($pdo, $memberUserId, true) === null) {
        throw new InvalidArgumentException('FitCrew member does not exist.');
    }

    fc_product_atomic($pdo, function () use ($pdo, $actorUserId, $crewId, $memberUserId): void {
        $select = $pdo->prepare('SELECT id, role_code FROM crew_memberships WHERE crew_id = :crew_id AND user_id = :user_id FOR UPDATE');
        $select->execute([':crew_id' => $crewId, ':user_id' => $memberUserId]);
        $existing = $select->fetch(PDO::FETCH_ASSOC);

        if ($existing === false) {
            $insert = $pdo->prepare(
                'INSERT INTO crew_memberships (crew_id, user_id, role_code, membership_status) ' .
                'VALUES (:crew_id, :user_id, \'MEMBER\', \'ACTIVE\')'
            );
            $insert->execute([':crew_id' => $crewId, ':user_id' => $memberUserId]);
        } elseif ((string) $existing['role_code'] !== 'OWNER') {
            $update = $pdo->prepare(
                'UPDATE crew_memberships SET membership_status = \'ACTIVE\', left_at = NULL, removed_at = NULL, joined_at = CURRENT_TIMESTAMP(6) ' .
                'WHERE id = :id'
            );
            $update->execute([':id' => (int) $existing['id']]);
        }

        fc_audit_event_write($pdo, [
            'actor_user_id' => $actorUserId,
            'event_type' => 'CREW_MEMBERSHIP_ACTIVATED',
            'target_type' => 'USER',
            'target_id' => (string) $memberUserId,
            'outcome' => 'SUCCESS',
            'group_id' => $crewId,
        ]);
    });
}
