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
        fc_family_lock_crew($pdo, $crewId);
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
function fc_challenges_for_user(PDO $pdo, int $userId, ?int $crewId = null, bool $history = false): array
{
    $sql =
        'SELECT DISTINCT c.*, cr.public_id AS crew_public_id, cr.display_name AS crew_name, ' .
        ' p.participation_status, p.entry_kind, ctl.effective_end_at, ctl.archived_at, ctl.deleted_at, ' .
        ' (SELECT COUNT(*) FROM challenge_participations cp WHERE cp.challenge_id = c.id AND cp.participation_status = \'ACTIVE\') AS participant_count ' .
        'FROM challenges c ' .
        'JOIN crews cr ON cr.id = c.crew_id ' .
        'LEFT JOIN challenge_owner_controls ctl ON ctl.challenge_id = c.id ' .
        'LEFT JOIN challenge_participations p ON p.challenge_id = c.id AND p.user_id = :participant_user_id ' .
        'WHERE (c.owner_user_id = :owner_user_id OR p.participation_status IN (\'ACTIVE\', \'WITHDRAWN\'))';
    $params = [':participant_user_id' => $userId, ':owner_user_id' => $userId];
    if (!$history) $sql .= ' AND ctl.archived_at IS NULL AND ctl.deleted_at IS NULL AND ctl.effective_end_at IS NULL';
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
function fc_challenge_summaries_for_crew(PDO $pdo, int $requestUserId, int $crewId, bool $history = true): array
{
    fc_crew_require_member($pdo, $requestUserId, $crewId);
    $statement = $pdo->prepare(
        'SELECT c.id, c.public_id, c.display_name, c.lifecycle_status, c.operational_state, c.owner_user_id, c.created_at, ctl.effective_end_at, ctl.archived_at, ctl.deleted_at, ' .
        ' (SELECT COUNT(*) FROM challenge_participations p WHERE p.challenge_id = c.id AND p.participation_status = \'ACTIVE\') AS participant_count ' .
        'FROM challenges c LEFT JOIN challenge_owner_controls ctl ON ctl.challenge_id = c.id WHERE c.crew_id = :crew_id AND ctl.deleted_at IS NULL ' .
        (!$history ? 'AND ctl.archived_at IS NULL AND ctl.effective_end_at IS NULL ' : '') .
        'ORDER BY CASE c.lifecycle_status WHEN \'COMPLETED\' THEN 1 ELSE 0 END, c.created_at DESC, c.id DESC'
    );
    $statement->execute([':crew_id' => $crewId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function fc_challenge_join(PDO $pdo, int $actorUserId, int $challengeId, int $expectedRuleId = 0, bool $accepted = false, array $privacy = []): void
{
    fc_challenge_accept_participation($pdo, $actorUserId, $challengeId, $expectedRuleId, $accepted, $privacy);
}

function fc_challenge_withdraw(PDO $pdo, int $actorUserId, int $challengeId): void
{
    fc_challenge_exit_participation($pdo, $actorUserId, $challengeId, $actorUserId, 'WITHDRAWN');
}

/** @return list<array<string,mixed>> */
function fc_challenge_participants(PDO $pdo, int $requestUserId, int $challengeId): array
{
    return fc_challenge_public_participant_cards($pdo, $requestUserId, $challengeId);
}

/** @return array<string,mixed>|null */
function fc_challenge_participation_for_user(PDO $pdo, int $challengeId, int $userId): ?array
{
    $statement = $pdo->prepare(
        'SELECT * FROM challenge_participations WHERE challenge_id = :challenge_id AND user_id = :user_id LIMIT 1' . fc_product_current_read($pdo)
    );
    $statement->execute([':challenge_id' => $challengeId, ':user_id' => $userId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Permanently delete only a pristine Draft Challenge. Published or participated
 * Challenges retain immutable history and are not eligible for hard deletion.
 */
function fc_challenge_delete_draft(PDO $pdo, int $actorUserId, int $challengeId): void
{
    fc_product_atomic($pdo, function () use ($pdo, $actorUserId, $challengeId): void {
        fc_family_lock_challenge($pdo, $challengeId);
        $challenge = fc_challenge_require_owner($pdo, $actorUserId, $challengeId);

        $lock = $pdo->prepare('SELECT * FROM challenges WHERE id = :id LIMIT 1 FOR UPDATE');
        $lock->execute([':id' => $challengeId]);
        $locked = $lock->fetch(PDO::FETCH_ASSOC);
        if ($locked === false) {
            throw new DomainException('Challenge is unavailable.');
        }
        if ((string) $locked['lifecycle_status'] !== 'DRAFT') {
            throw new DomainException('Only an unpublished Draft Challenge can be deleted.');
        }

        $published = $pdo->prepare(
            'SELECT id FROM challenge_rule_versions ' .
            'WHERE challenge_id = :challenge_id AND version_status = \'PUBLISHED\' LIMIT 1 FOR UPDATE'
        );
        $published->execute([':challenge_id' => $challengeId]);
        if ($published->fetchColumn() !== false) {
            throw new DomainException('A Challenge with published Rules cannot be deleted.');
        }

        $participations = $pdo->prepare('SELECT id FROM challenge_participations WHERE challenge_id = :challenge_id LIMIT 1 FOR UPDATE');
        $participations->execute([':challenge_id' => $challengeId]);
        if ($participations->fetchColumn() !== false) {
            throw new DomainException('A Challenge with participant history cannot be deleted.');
        }

        // Any newer consent/offer/management history makes this a soft-delete case.
        foreach (['challenge_product_events', 'challenge_participant_offers', 'challenge_acceptance_records', 'challenge_participation_intervals', 'challenge_privacy_preferences'] as $historyTable) {
            $history = $pdo->prepare('SELECT challenge_id FROM ' . $historyTable . ' WHERE challenge_id = :id LIMIT 1 FOR UPDATE');
            $history->execute([':id' => $challengeId]);
            if ($history->fetchColumn() !== false) throw new DomainException('Use Delete Challenge to preserve this Challenge history.');
        }
        $controls = fc_challenge_management_state($pdo, $challengeId);
        if ((int) $controls['revision'] !== 0) throw new DomainException('Use Delete Challenge to preserve this Challenge history.');

        fc_audit_event_write($pdo, [
            'actor_user_id' => $actorUserId,
            'event_type' => 'CHALLENGE_DRAFT_DELETED',
            'target_type' => 'CHALLENGE',
            'target_id' => (string) $challenge['public_id'],
            'outcome' => 'SUCCESS',
            'group_id' => (int) $challenge['crew_id'],
        ]);

        $pdo->prepare('DELETE FROM challenge_owner_controls WHERE challenge_id = :id')->execute([':id' => $challengeId]);

        $deleteRules = $pdo->prepare(
            'DELETE FROM challenge_rule_versions WHERE challenge_id = :challenge_id AND version_status = \'DRAFT\''
        );
        $deleteRules->execute([':challenge_id' => $challengeId]);

        $deleteChallenge = $pdo->prepare('DELETE FROM challenges WHERE id = :challenge_id AND lifecycle_status = \'DRAFT\'');
        $deleteChallenge->execute([':challenge_id' => $challengeId]);
        if ($deleteChallenge->rowCount() !== 1) {
            throw new RuntimeException('Challenge Draft deletion did not complete.');
        }
    });
}

function fc_challenge_delete_draft_public(PDO $pdo, int $actorUserId, string $challengePublicId): void
{
    $challenge = fc_challenge_require_public($pdo, $actorUserId, trim($challengePublicId));
    fc_challenge_delete_draft($pdo, $actorUserId, (int) $challenge['id']);
}
