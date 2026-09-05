<?php

declare(strict_types=1);

/** @return array{id:int,public_id:string} */
function fc_challenge_rule_draft_create(PDO $pdo, int $challengeId, int $actorUserId, array $values = []): array
{
    return fc_product_atomic($pdo, function () use ($pdo, $challengeId, $actorUserId, $values): array {
        fc_challenge_require_owner($pdo, $actorUserId, $challengeId);

        // Serialize draft/version creation per Challenge so two Owner requests cannot
        // create competing version numbers or parallel drafts.
        $challengeLock = $pdo->prepare('SELECT id FROM challenges WHERE id = :challenge_id FOR UPDATE');
        $challengeLock->execute([':challenge_id' => $challengeId]);
        if ($challengeLock->fetchColumn() === false) {
            throw new DomainException('Challenge is unavailable.');
        }

        $existing = $pdo->prepare(
            'SELECT id FROM challenge_rule_versions WHERE challenge_id = :challenge_id AND version_status = \'DRAFT\' LIMIT 1'
        );
        $existing->execute([':challenge_id' => $challengeId]);
        if ($existing->fetchColumn() !== false) {
            throw new DomainException('A Challenge rule draft already exists.');
        }

        $versionStatement = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM challenge_rule_versions WHERE challenge_id = :challenge_id');
        $versionStatement->execute([':challenge_id' => $challengeId]);
        $version = (int) $versionStatement->fetchColumn();

        $timezone = trim((string) ($values['challenge_timezone'] ?? fc_config()['timezone']));
        if (!fc_product_timezone_is_valid($timezone)) {
            throw new InvalidArgumentException('Challenge timezone is invalid.');
        }
        $duration = (int) ($values['duration_days'] ?? 56);
        if ($duration < 7 || $duration > 365) {
            throw new InvalidArgumentException('Challenge duration must be between 7 and 365 days.');
        }
        $checkinDay = (int) ($values['weekly_checkin_day'] ?? 0);
        if ($checkinDay < 0 || $checkinDay > 6) {
            throw new InvalidArgumentException('Weekly check-in day must be between Sunday and Saturday.');
        }
        $plannedStart = fc_rule_date_or_null($values['planned_start_date'] ?? null);
        $liveVisible = !array_key_exists('live_leaderboard_visible', $values) || (bool) $values['live_leaderboard_visible'];
        $publicId = fc_new_public_id();
        $supersedesVersionId = isset($values['supersedes_version_id']) ? (int) $values['supersedes_version_id'] : null;
        if ($supersedesVersionId !== null && $supersedesVersionId <= 0) {
            $supersedesVersionId = null;
        }
        if ($supersedesVersionId !== null) {
            $supersedesCheck = $pdo->prepare(
                "SELECT id FROM challenge_rule_versions WHERE id = :id AND challenge_id = :challenge_id AND version_status = 'PUBLISHED' LIMIT 1"
            );
            $supersedesCheck->execute([':id' => $supersedesVersionId, ':challenge_id' => $challengeId]);
            if ($supersedesCheck->fetchColumn() === false) {
                throw new InvalidArgumentException('Superseded Rule Version must be a published version of this Challenge.');
            }
        }

        $statement = $pdo->prepare(
            'INSERT INTO challenge_rule_versions (public_id, challenge_id, version_number, version_status, supersedes_version_id, scoring_standard_code, ' .
            ' planned_start_date, duration_days, challenge_timezone, weekly_checkin_day, live_leaderboard_visible, created_by_user_id) ' .
            'VALUES (:public_id, :challenge_id, :version_number, \'DRAFT\', :supersedes_version_id, :scoring_standard_code, :planned_start_date, :duration_days, ' .
            ' :challenge_timezone, :weekly_checkin_day, :live_visible, :created_by_user_id)'
        );
        $statement->execute([
            ':public_id' => $publicId,
            ':challenge_id' => $challengeId,
            ':version_number' => $version,
            ':supersedes_version_id' => $supersedesVersionId,
            ':scoring_standard_code' => FC_CERTIFIED_SCORING_STANDARD,
            ':planned_start_date' => $plannedStart,
            ':duration_days' => $duration,
            ':challenge_timezone' => $timezone,
            ':weekly_checkin_day' => $checkinDay,
            ':live_visible' => $liveVisible ? 1 : 0,
            ':created_by_user_id' => $actorUserId,
        ]);

        return ['id' => (int) $pdo->lastInsertId(), 'public_id' => $publicId];
    });
}

function fc_rule_date_or_null(mixed $value): ?string
{
    $value = trim((string) ($value ?? ''));
    if ($value === '') {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$parsed || $parsed->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Challenge start date must be a valid date.');
    }
    return $value;
}

/** @return array<string,mixed>|null */
function fc_challenge_rule_current_published(PDO $pdo, int $challengeId): ?array
{
    $statement = $pdo->prepare(
        'SELECT * FROM challenge_rule_versions ' .
        'WHERE challenge_id = :challenge_id AND version_status = \'PUBLISHED\' ' .
        'ORDER BY version_number DESC LIMIT 1'
    );
    $statement->execute([':challenge_id' => $challengeId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** @return array<string,mixed>|null */
function fc_challenge_rule_current_draft(PDO $pdo, int $challengeId): ?array
{
    $statement = $pdo->prepare(
        'SELECT * FROM challenge_rule_versions ' .
        'WHERE challenge_id = :challenge_id AND version_status = \'DRAFT\' ' .
        'ORDER BY version_number DESC LIMIT 1'
    );
    $statement->execute([':challenge_id' => $challengeId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** @return list<array<string,mixed>> */
function fc_challenge_rule_history(PDO $pdo, int $requestUserId, int $challengeId): array
{
    $challenge = fc_challenge_require_access($pdo, $requestUserId, $challengeId);
    $owner = (int) $challenge['owner_user_id'] === $requestUserId;

    $sql = 'SELECT * FROM challenge_rule_versions WHERE challenge_id = :challenge_id';
    if (!$owner) {
        $sql .= ' AND version_status = \'PUBLISHED\'';
    }
    $sql .= ' ORDER BY version_number DESC';

    $statement = $pdo->prepare($sql);
    $statement->execute([':challenge_id' => $challengeId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function fc_challenge_rule_save_draft(PDO $pdo, int $actorUserId, int $challengeId, int $ruleId, array $values): void
{
    fc_product_atomic($pdo, function () use ($pdo, $actorUserId, $challengeId, $ruleId, $values): void {
        fc_challenge_require_owner($pdo, $actorUserId, $challengeId);

        $statement = $pdo->prepare(
            'SELECT * FROM challenge_rule_versions WHERE id = :id AND challenge_id = :challenge_id LIMIT 1 FOR UPDATE'
        );
        $statement->execute([':id' => $ruleId, ':challenge_id' => $challengeId]);
        $rule = $statement->fetch(PDO::FETCH_ASSOC);
        if ($rule === false || (string) $rule['version_status'] !== 'DRAFT') {
            throw new DomainException('Only a current draft rule version can be edited.');
        }

        $timezone = trim((string) ($values['challenge_timezone'] ?? ''));
        if (!fc_product_timezone_is_valid($timezone)) {
            throw new InvalidArgumentException('Challenge timezone is invalid.');
        }
        $duration = (int) ($values['duration_days'] ?? 0);
        if ($duration < 7 || $duration > 365) {
            throw new InvalidArgumentException('Challenge duration must be between 7 and 365 days.');
        }
        $checkinDay = (int) ($values['weekly_checkin_day'] ?? -1);
        if ($checkinDay < 0 || $checkinDay > 6) {
            throw new InvalidArgumentException('Weekly check-in day is invalid.');
        }
        $plannedStart = fc_rule_date_or_null($values['planned_start_date'] ?? null);
        $liveVisible = (bool) ($values['live_leaderboard_visible'] ?? false);

        $update = $pdo->prepare(
            'UPDATE challenge_rule_versions SET planned_start_date = :planned_start_date, duration_days = :duration_days, ' .
            ' challenge_timezone = :challenge_timezone, weekly_checkin_day = :weekly_checkin_day, live_leaderboard_visible = :live_visible ' .
            'WHERE id = :id AND version_status = \'DRAFT\''
        );
        $update->execute([
            ':planned_start_date' => $plannedStart,
            ':duration_days' => $duration,
            ':challenge_timezone' => $timezone,
            ':weekly_checkin_day' => $checkinDay,
            ':live_visible' => $liveVisible ? 1 : 0,
            ':id' => $ruleId,
        ]);
    });
}

function fc_challenge_rule_publish(PDO $pdo, int $actorUserId, int $challengeId, int $ruleId): void
{
    fc_product_atomic($pdo, function () use ($pdo, $actorUserId, $challengeId, $ruleId): void {
        $challenge = fc_challenge_require_owner($pdo, $actorUserId, $challengeId);
        $statement = $pdo->prepare(
            'SELECT * FROM challenge_rule_versions WHERE id = :id AND challenge_id = :challenge_id LIMIT 1 FOR UPDATE'
        );
        $statement->execute([':id' => $ruleId, ':challenge_id' => $challengeId]);
        $rule = $statement->fetch(PDO::FETCH_ASSOC);
        if ($rule === false || (string) $rule['version_status'] !== 'DRAFT') {
            throw new DomainException('Only a draft rule version can be published.');
        }
        if ($rule['planned_start_date'] === null) {
            throw new DomainException('Set a planned Challenge start date before publishing the rules.');
        }

        $publish = $pdo->prepare(
            'UPDATE challenge_rule_versions SET version_status = \'PUBLISHED\', published_by_user_id = :user_id, ' .
            ' published_at = CURRENT_TIMESTAMP(6) WHERE id = :id AND version_status = \'DRAFT\''
        );
        $publish->execute([':user_id' => $actorUserId, ':id' => $ruleId]);

        if ((string) $challenge['lifecycle_status'] === 'DRAFT') {
            $advance = $pdo->prepare(
                'UPDATE challenges SET lifecycle_status = \'FORMING_CREW\' WHERE id = :challenge_id AND lifecycle_status = \'DRAFT\''
            );
            $advance->execute([':challenge_id' => $challengeId]);
        }

        fc_audit_event_write($pdo, [
            'actor_user_id' => $actorUserId,
            'event_type' => 'CHALLENGE_RULE_VERSION_PUBLISHED',
            'target_type' => 'CHALLENGE_RULE_VERSION',
            'target_id' => (string) $rule['public_id'],
            'outcome' => 'SUCCESS',
            'group_id' => (int) $challenge['crew_id'],
            'metadata' => ['version_number' => (int) $rule['version_number']],
        ]);
    });
}

/** @return array{id:int,public_id:string} */
function fc_challenge_rule_begin_update(PDO $pdo, int $actorUserId, int $challengeId): array
{
    return fc_product_atomic($pdo, function () use ($pdo, $actorUserId, $challengeId): array {
        $challenge = fc_challenge_require_owner($pdo, $actorUserId, $challengeId);
        $draft = fc_challenge_rule_current_draft($pdo, $challengeId);
        if ($draft !== null) {
            return ['id' => (int) $draft['id'], 'public_id' => (string) $draft['public_id']];
        }

        $current = fc_challenge_rule_current_published($pdo, $challengeId);
        if ($current === null) {
            throw new DomainException('Publish the initial Challenge rules before preparing an update.');
        }

        $result = fc_challenge_rule_draft_create($pdo, $challengeId, $actorUserId, [
            'supersedes_version_id' => (int) $current['id'],
            'planned_start_date' => $current['planned_start_date'],
            'duration_days' => (int) $current['duration_days'],
            'challenge_timezone' => (string) $current['challenge_timezone'],
            'weekly_checkin_day' => (int) $current['weekly_checkin_day'],
            'live_leaderboard_visible' => (bool) $current['live_leaderboard_visible'],
        ]);

        fc_audit_event_write($pdo, [
            'actor_user_id' => $actorUserId,
            'event_type' => 'CHALLENGE_RULE_UPDATE_DRAFTED',
            'target_type' => 'CHALLENGE',
            'target_id' => (string) $challenge['public_id'],
            'outcome' => 'SUCCESS',
            'group_id' => (int) $challenge['crew_id'],
        ]);

        return $result;
    });
}
