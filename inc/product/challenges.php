<?php

declare(strict_types=1);

/** @return array{id:int,public_id:string} */
function fc_challenge_create(PDO $pdo, int $actorUserId, int $crewId, string $displayName, array $ruleValues = []): array
{
    $displayName = trim($displayName);
    if ($displayName === '' || strlen($displayName) > 140) {
        throw new InvalidArgumentException('Challenge name is required and must be 140 characters or fewer.');
    }

    return fc_product_atomic($pdo, function () use ($pdo, $actorUserId, $crewId, $displayName, $ruleValues): array {
        fc_crew_require_owner($pdo, $actorUserId, $crewId);
        $publicId = fc_new_public_id();

        $insert = $pdo->prepare(
            'INSERT INTO challenges (public_id, crew_id, owner_user_id, display_name, lifecycle_status, operational_state) ' .
            'VALUES (:public_id, :crew_id, :owner_user_id, :display_name, \'DRAFT\', \'NORMAL\')'
        );
        $insert->execute([
            ':public_id' => $publicId,
            ':crew_id' => $crewId,
            ':owner_user_id' => $actorUserId,
            ':display_name' => $displayName,
        ]);
        $challengeId = (int) $pdo->lastInsertId();

        fc_challenge_rule_draft_create($pdo, $challengeId, $actorUserId, $ruleValues);

        fc_audit_event_write($pdo, [
            'actor_user_id' => $actorUserId,
            'event_type' => 'CHALLENGE_CREATED',
            'target_type' => 'CHALLENGE',
            'target_id' => $publicId,
            'outcome' => 'SUCCESS',
            'group_id' => $crewId,
        ]);

        return ['id' => $challengeId, 'public_id' => $publicId];
    });
}

/** @return array<string,mixed> */
function fc_challenge_require_public(PDO $pdo, int $userId, string $publicId): array
{
    $statement = $pdo->prepare('SELECT id FROM challenges WHERE public_id = :public_id LIMIT 1');
    $statement->execute([':public_id' => trim($publicId)]);
    $challengeId = $statement->fetchColumn();
    if ($challengeId === false) {
        fc_product_access_denied('Challenge access denied.');
    }
    return fc_challenge_require_access($pdo, $userId, (int) $challengeId);
}

/** @return list<array<string,mixed>> */
function fc_challenges_for_user(PDO $pdo, int $userId, ?int $crewId = null): array
{
    $sql =
        'SELECT DISTINCT c.*, cr.public_id AS crew_public_id, cr.display_name AS crew_name, ' .
        ' p.participation_status, p.entry_kind ' .
        'FROM challenges c ' .
        'JOIN crews cr ON cr.id = c.crew_id ' .
        'LEFT JOIN challenge_participations p ON p.challenge_id = c.id AND p.user_id = :participant_user_id ' .
        'WHERE (c.owner_user_id = :owner_user_id OR p.participation_status IN (\'ACTIVE\', \'WITHDRAWN\'))';
    $params = [':participant_user_id' => $userId, ':owner_user_id' => $userId];
    if ($crewId !== null) {
        $sql .= ' AND c.crew_id = :crew_id';
        $params[':crew_id'] = $crewId;
    }
    $sql .= ' ORDER BY CASE c.lifecycle_status WHEN \'COMPLETED\' THEN 1 ELSE 0 END, c.created_at DESC, c.id DESC';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Crew-safe Challenge summary. This intentionally exposes only Challenge shell truth,
 * not participant, health, scoring, or rules detail.
 * @return list<array<string,mixed>>
 */
function fc_challenge_summaries_for_crew(PDO $pdo, int $requestUserId, int $crewId): array
{
    fc_crew_require_member($pdo, $requestUserId, $crewId);
    $statement = $pdo->prepare(
        'SELECT c.id, c.public_id, c.display_name, c.lifecycle_status, c.operational_state, c.owner_user_id, c.created_at, ' .
        ' (SELECT COUNT(*) FROM challenge_participations p WHERE p.challenge_id = c.id AND p.participation_status = \'ACTIVE\') AS participant_count ' .
        'FROM challenges c WHERE c.crew_id = :crew_id ' .
        'ORDER BY CASE c.lifecycle_status WHEN \'COMPLETED\' THEN 1 ELSE 0 END, c.created_at DESC, c.id DESC'
    );
    $statement->execute([':crew_id' => $crewId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function fc_challenge_join(PDO $pdo, int $actorUserId, int $challengeId): void
{
    fc_product_atomic($pdo, function () use ($pdo, $actorUserId, $challengeId): void {
        $challengeStatement = $pdo->prepare('SELECT * FROM challenges WHERE id = :id LIMIT 1 FOR UPDATE');
        $challengeStatement->execute([':id' => $challengeId]);
        $challenge = $challengeStatement->fetch(PDO::FETCH_ASSOC);
        if ($challenge === false) {
            fc_product_access_denied('Challenge is unavailable.');
        }

        fc_crew_require_member($pdo, $actorUserId, (int) $challenge['crew_id']);
        if (!in_array((string) $challenge['lifecycle_status'], ['DRAFT', 'FORMING_CREW'], true)) {
            throw new DomainException('Challenge participation cannot be joined at this lifecycle state.');
        }

        $select = $pdo->prepare(
            'SELECT * FROM challenge_participations WHERE challenge_id = :challenge_id AND user_id = :user_id LIMIT 1 FOR UPDATE'
        );
        $select->execute([':challenge_id' => $challengeId, ':user_id' => $actorUserId]);
        $existing = $select->fetch(PDO::FETCH_ASSOC);
        $entryKind = (string) $challenge['lifecycle_status'] === 'DRAFT' ? 'STANDARD' : 'STANDARD';

        if ($existing === false) {
            $insert = $pdo->prepare(
                'INSERT INTO challenge_participations (challenge_id, user_id, participation_status, entry_kind) ' .
                'VALUES (:challenge_id, :user_id, \'ACTIVE\', :entry_kind)'
            );
            $insert->execute([':challenge_id' => $challengeId, ':user_id' => $actorUserId, ':entry_kind' => $entryKind]);
        } elseif ((string) $existing['participation_status'] !== 'REMOVED') {
            $update = $pdo->prepare(
                'UPDATE challenge_participations SET participation_status = \'ACTIVE\', withdrawn_at = NULL, ' .
                ' entry_kind = :entry_kind WHERE id = :id'
            );
            $update->execute([':entry_kind' => $entryKind, ':id' => (int) $existing['id']]);
        } else {
            throw new DomainException('This participation was removed and cannot be reactivated through self-service.');
        }

        fc_audit_event_write($pdo, [
            'actor_user_id' => $actorUserId,
            'event_type' => 'CHALLENGE_PARTICIPATION_ACTIVATED',
            'target_type' => 'CHALLENGE',
            'target_id' => (string) $challenge['public_id'],
            'outcome' => 'SUCCESS',
            'group_id' => (int) $challenge['crew_id'],
        ]);
    });
}

function fc_challenge_withdraw(PDO $pdo, int $actorUserId, int $challengeId): void
{
    fc_product_atomic($pdo, function () use ($pdo, $actorUserId, $challengeId): void {
        $challenge = fc_challenge_require_access($pdo, $actorUserId, $challengeId);
        $statement = $pdo->prepare(
            'UPDATE challenge_participations SET participation_status = \'WITHDRAWN\', withdrawn_at = CURRENT_TIMESTAMP(6) ' .
            'WHERE challenge_id = :challenge_id AND user_id = :user_id AND participation_status = \'ACTIVE\''
        );
        $statement->execute([':challenge_id' => $challengeId, ':user_id' => $actorUserId]);
        if ($statement->rowCount() !== 1) {
            throw new DomainException('No active Challenge participation was available to withdraw.');
        }

        fc_audit_event_write($pdo, [
            'actor_user_id' => $actorUserId,
            'event_type' => 'CHALLENGE_PARTICIPATION_WITHDRAWN',
            'target_type' => 'CHALLENGE',
            'target_id' => (string) $challenge['public_id'],
            'outcome' => 'SUCCESS',
            'group_id' => (int) $challenge['crew_id'],
        ]);
    });
}

/** @return list<array<string,mixed>> */
function fc_challenge_participants(PDO $pdo, int $requestUserId, int $challengeId): array
{
    fc_challenge_require_access($pdo, $requestUserId, $challengeId);
    $statement = $pdo->prepare(
        'SELECT p.*, u.display_name, u.public_id AS user_public_id ' .
        'FROM challenge_participations p JOIN users u ON u.id = p.user_id ' .
        'WHERE p.challenge_id = :challenge_id ' .
        'ORDER BY CASE p.participation_status WHEN \'ACTIVE\' THEN 0 ELSE 1 END, p.joined_at, p.id'
    );
    $statement->execute([':challenge_id' => $challengeId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string,mixed>|null */
function fc_challenge_participation_for_user(PDO $pdo, int $challengeId, int $userId): ?array
{
    $statement = $pdo->prepare(
        'SELECT * FROM challenge_participations WHERE challenge_id = :challenge_id AND user_id = :user_id LIMIT 1'
    );
    $statement->execute([':challenge_id' => $challengeId, ':user_id' => $userId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
