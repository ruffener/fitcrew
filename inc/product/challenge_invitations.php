<?php

declare(strict_types=1);

const FC_CHALLENGE_INVITATION_REVIEW_SESSION_KEY = 'fitcrew_challenge_invitation_review';
const FC_CHALLENGE_INVITATION_INTENT_SESSION_KEY = 'fitcrew_challenge_invitation_acceptance_intent';
const FC_CHALLENGE_INVITATION_INTENT_TTL_SECONDS = 1800;

/** @param array{invitation_public_id:string,generation:int} $review */
function fc_challenge_invitation_review_session_set(array $review): void
{
    $_SESSION[FC_CHALLENGE_INVITATION_REVIEW_SESSION_KEY] = [
        'invitation_public_id' => (string) $review['invitation_public_id'],
        'generation' => (int) $review['generation'],
    ];
}

/** @return array{invitation_public_id:string,generation:int}|null */
function fc_challenge_invitation_review_session_current(): ?array
{
    $value = $_SESSION[FC_CHALLENGE_INVITATION_REVIEW_SESSION_KEY] ?? null;
    if (!is_array($value)) return null;
    $publicId = trim((string) ($value['invitation_public_id'] ?? ''));
    $generation = filter_var($value['generation'] ?? null, FILTER_VALIDATE_INT);
    if (!fc_public_id_is_valid($publicId) || $generation === false || $generation < 0) {
        unset($_SESSION[FC_CHALLENGE_INVITATION_REVIEW_SESSION_KEY]);
        return null;
    }
    return ['invitation_public_id' => $publicId, 'generation' => (int) $generation];
}

function fc_challenge_invitation_review_session_clear(): void
{
    unset($_SESSION[FC_CHALLENGE_INVITATION_REVIEW_SESSION_KEY]);
}

function fc_challenge_invitation_intent_session_set(string $publicId): void
{
    if (!fc_public_id_is_valid($publicId)) throw new InvalidArgumentException('Acceptance intent is invalid.');
    $_SESSION[FC_CHALLENGE_INVITATION_INTENT_SESSION_KEY] = $publicId;
}

function fc_challenge_invitation_intent_session_public_id(): ?string
{
    $value = $_SESSION[FC_CHALLENGE_INVITATION_INTENT_SESSION_KEY] ?? null;
    if (!is_string($value) || !fc_public_id_is_valid($value)) {
        unset($_SESSION[FC_CHALLENGE_INVITATION_INTENT_SESSION_KEY]);
        return null;
    }
    return $value;
}

function fc_challenge_invitation_intent_session_clear(?string $expectedPublicId = null): void
{
    $current = $_SESSION[FC_CHALLENGE_INVITATION_INTENT_SESSION_KEY] ?? null;
    if ($expectedPublicId === null || (is_string($current) && hash_equals($expectedPublicId, $current))) {
        unset($_SESSION[FC_CHALLENGE_INVITATION_INTENT_SESSION_KEY]);
    }
}

/**
 * Restore the single current Website acceptance intent after an Auth-owned
 * session reset/account switch. The invitation generation remains the lookup
 * authority; no acceptance choices travel through Auth or the browser.
 */
function fc_challenge_invitation_intent_resume_for_invitation(
    PDO $pdo,
    string $invitationPublicId,
    int $generation
): ?string {
    $q = $pdo->prepare(
        'SELECT ai.public_id FROM challenge_invitation_acceptance_intents ai ' .
        'JOIN crew_invitations i ON i.id=ai.invitation_id ' .
        'WHERE i.public_id=:invitation AND ai.invitation_generation=:generation ' .
        'AND ai.intent_status=\'PENDING\' AND ai.expires_at > CURRENT_TIMESTAMP(6) ' .
        'ORDER BY ai.id DESC LIMIT 1'
    );
    $q->execute([':invitation' => $invitationPublicId, ':generation' => $generation]);
    $publicId = $q->fetchColumn();
    if ($publicId === false) return null;
    fc_challenge_invitation_intent_session_set((string) $publicId);
    return (string) $publicId;
}

/**
 * Invitation-safe Challenge review. This intentionally returns no roster,
 * standings, health measurement, provider payload, or other private member data.
 * @return array<string,mixed>|null
 */
function fc_challenge_invitation_review(PDO $pdo, string $invitationPublicId, int $generation, bool $forUpdate = false): ?array
{
    if ($forUpdate && !$pdo->inTransaction()) {
        throw new LogicException('Locking Challenge invitation review requires an active transaction.');
    }
    $snapshot = fc_crew_invitation_auth_snapshot($pdo, $invitationPublicId, $generation, $forUpdate);
    if ($snapshot === null) return null;

    $sql =
        'SELECT i.id AS invitation_id,i.public_id AS invitation_public_id,i.crew_id,i.challenge_id,' .
        'i.resend_count AS generation,i.expires_at,i.invited_by_user_id,i.invited_email,' .
        'cr.public_id AS crew_public_id,cr.display_name AS crew_name,cr.description AS crew_description,' .
        'c.public_id AS challenge_public_id,c.display_name AS challenge_name,c.lifecycle_status,c.operational_state,' .
        'u.display_name AS inviter_name,' .
        'rv.id AS rule_version_id,rv.version_number,rv.scoring_standard_code,rv.planned_start_date,rv.duration_days,' .
        'rv.challenge_timezone,rv.weekly_checkin_day,rv.live_leaderboard_visible,rv.published_at ' .
        'FROM crew_invitations i ' .
        'JOIN crews cr ON cr.id=i.crew_id ' .
        'JOIN challenges c ON c.id=i.challenge_id AND c.crew_id=i.crew_id ' .
        'JOIN crew_current_challenges cc ON cc.crew_id=i.crew_id AND cc.challenge_id=i.challenge_id ' .
        'JOIN users u ON u.id=i.invited_by_user_id ' .
        'JOIN challenge_rule_versions rv ON rv.id=(' .
        '  SELECT rv2.id FROM challenge_rule_versions rv2 WHERE rv2.challenge_id=c.id AND rv2.version_status=\'PUBLISHED\' ORDER BY rv2.version_number DESC LIMIT 1' .
        ') ' .
        'LEFT JOIN challenge_owner_controls ctl ON ctl.challenge_id=c.id ' .
        'WHERE i.public_id=:public_id AND i.resend_count=:generation ' .
        'AND i.invitation_status=\'PENDING\' AND i.challenge_id IS NOT NULL ' .
        'AND i.expires_at > CURRENT_TIMESTAMP(6) ' .
        'AND i.accepted_at IS NULL AND i.cancelled_at IS NULL ' .
        'AND c.lifecycle_status <> \'COMPLETED\' AND c.completed_at IS NULL ' .
        'AND ctl.effective_end_at IS NULL AND ctl.archived_at IS NULL AND ctl.deleted_at IS NULL ' .
        'LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $q = $pdo->prepare($sql);
    $q->execute([':public_id' => $invitationPublicId, ':generation' => $generation]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** @return array<string,mixed>|null */
function fc_challenge_invitation_review_current(PDO $pdo): ?array
{
    $session = fc_challenge_invitation_review_session_current();
    if ($session === null) return null;
    $review = fc_challenge_invitation_review($pdo, $session['invitation_public_id'], $session['generation']);
    if ($review === null) fc_challenge_invitation_review_session_clear();
    return $review;
}

/** @return array<string,mixed> */
function fc_challenge_invitation_intent_create(PDO $pdo, array $review, array $privacy): array
{
    $choices = fc_challenge_privacy_validate($privacy);
    $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+' . FC_CHALLENGE_INVITATION_INTENT_TTL_SECONDS . ' seconds');
    $invitationExpiry = new DateTimeImmutable((string) $review['expires_at'], new DateTimeZone('UTC'));
    if ($invitationExpiry < $expires) $expires = $invitationExpiry;

    return fc_product_atomic($pdo, function () use ($pdo, $review, $choices, $expires): array {
        $fresh = fc_challenge_invitation_review(
            $pdo,
            (string) $review['invitation_public_id'],
            (int) $review['generation'],
            true
        );
        if ($fresh === null) throw new DomainException('This invitation changed or expired. Open the latest invitation email.');
        if ((int) $fresh['rule_version_id'] !== (int) $review['rule_version_id']) {
            throw new DomainException('The Challenge Rules changed. Review the current terms before accepting.');
        }

        $pdo->prepare(
            'UPDATE challenge_invitation_acceptance_intents SET intent_status=\'INVALIDATED\' ' .
            'WHERE invitation_id=:invitation_id AND invitation_generation=:generation AND intent_status=\'PENDING\''
        )->execute([
            ':invitation_id' => (int) $fresh['invitation_id'],
            ':generation' => (int) $fresh['generation'],
        ]);

        $publicId = fc_new_public_id();
        $q = $pdo->prepare(
            'INSERT INTO challenge_invitation_acceptance_intents ' .
            '(public_id,invitation_id,invitation_generation,crew_id,challenge_id,rule_version_id,consent_version,' .
            'measurements_visibility,progress_visibility,expires_at) ' .
            'VALUES (:public_id,:invitation_id,:generation,:crew_id,:challenge_id,:rule_version_id,:consent_version,:m,:p,:expires_at)'
        );
        $q->execute([
            ':public_id' => $publicId,
            ':invitation_id' => (int) $fresh['invitation_id'],
            ':generation' => (int) $fresh['generation'],
            ':crew_id' => (int) $fresh['crew_id'],
            ':challenge_id' => (int) $fresh['challenge_id'],
            ':rule_version_id' => (int) $fresh['rule_version_id'],
            ':consent_version' => FC_FAMILY_ALPHA_CONTRACT,
            ':m' => $choices['measurements_visibility'],
            ':p' => $choices['progress_visibility'],
            ':expires_at' => $expires->format('Y-m-d H:i:s.u'),
        ]);
        return ['public_id' => $publicId, 'expires_at' => $expires->format('Y-m-d H:i:s.u')];
    });
}

/** @return array<string,mixed>|null */
function fc_challenge_invitation_intent_current(PDO $pdo, bool $forUpdate = false): ?array
{
    $publicId = fc_challenge_invitation_intent_session_public_id();
    if ($publicId === null) return null;
    if ($forUpdate && !$pdo->inTransaction()) {
        throw new LogicException('Locking acceptance intent requires an active transaction.');
    }
    $sql = 'SELECT * FROM challenge_invitation_acceptance_intents WHERE public_id=:public_id AND intent_status=\'PENDING\' AND expires_at > CURRENT_TIMESTAMP(6) LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $q = $pdo->prepare($sql);
    $q->execute([':public_id' => $publicId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row === false && !$pdo->inTransaction()) fc_challenge_invitation_intent_session_clear($publicId);
    return $row === false ? null : $row;
}

function fc_challenge_invitation_intent_invalidate(PDO $pdo, string $publicId): void
{
    $q = $pdo->prepare(
        'UPDATE challenge_invitation_acceptance_intents SET intent_status=\'INVALIDATED\' ' .
        'WHERE public_id=:public_id AND intent_status=\'PENDING\''
    );
    $q->execute([':public_id' => $publicId]);
    fc_challenge_invitation_intent_session_clear($publicId);
}

/** @return array{crew_id:int,challenge_id:int,already_enrolled:bool} */
function fc_challenge_invitation_enroll(
    PDO $pdo,
    int $actorUserId,
    bool $consumeAuthContinuation
): array {
    if (!$pdo->inTransaction()) {
        throw new LogicException('Challenge invitation enrollment requires one caller-owned transaction.');
    }
    if (fc_user_find_by_id($pdo, $actorUserId, true) === null) {
        throw new DomainException('Signed-in FitCrew user is unavailable.');
    }

    $intent = fc_challenge_invitation_intent_current($pdo, true);
    if ($intent === null) throw new DomainException('Your Challenge acceptance expired. Review the invitation again.');

    $invitation = fc_crew_invitation_auth_snapshot(
        $pdo,
        (string) fc_challenge_invitation_intent_invitation_public_id($pdo, (int) $intent['invitation_id']),
        (int) $intent['invitation_generation'],
        true
    );
    if ($invitation === null) throw new DomainException('This invitation changed or expired. Open the latest invitation email.');

    $q = $pdo->prepare(
        'SELECT i.*,c.lifecycle_status,c.completed_at FROM crew_invitations i ' .
        'JOIN challenges c ON c.id=i.challenge_id AND c.crew_id=i.crew_id ' .
        'JOIN crew_current_challenges cc ON cc.crew_id=i.crew_id AND cc.challenge_id=i.challenge_id ' .
        'LEFT JOIN challenge_owner_controls ctl ON ctl.challenge_id=c.id ' .
        'WHERE i.id=:id AND i.public_id=:public_id AND i.resend_count=:generation ' .
        'AND i.invitation_status=\'PENDING\' AND i.challenge_id IS NOT NULL ' .
        'AND c.lifecycle_status <> \'COMPLETED\' AND c.completed_at IS NULL ' .
        'AND ctl.effective_end_at IS NULL AND ctl.archived_at IS NULL AND ctl.deleted_at IS NULL LIMIT 1 FOR UPDATE'
    );
    $q->execute([
        ':id' => (int) $intent['invitation_id'],
        ':public_id' => (string) $invitation['invitation_public_id'],
        ':generation' => (int) $intent['invitation_generation'],
    ]);
    $inv = $q->fetch(PDO::FETCH_ASSOC);
    if ($inv === false) throw new DomainException('This Challenge invitation is no longer current.');
    if ((int) $inv['crew_id'] !== (int) $intent['crew_id'] || (int) $inv['challenge_id'] !== (int) $intent['challenge_id']) {
        throw new DomainException('This Challenge invitation no longer matches what you reviewed.');
    }

    fc_family_lock_crew($pdo, (int) $intent['crew_id']);
    $current = fc_crew_current_challenge($pdo, (int) $intent['crew_id'], true);
    if ($current === null || (int) $current['id'] !== (int) $intent['challenge_id']) {
        throw new DomainException('This Crew has moved to a different Challenge. Review the current invitation.');
    }
    $rule = fc_challenge_rule_current_published($pdo, (int) $intent['challenge_id']);
    if ($rule === null || (int) $rule['id'] !== (int) $intent['rule_version_id']) {
        throw new DomainException('The Challenge Rules changed while you were signing in. Review the current terms before accepting.');
    }
    if (!hash_equals((string) $intent['consent_version'], FC_FAMILY_ALPHA_CONTRACT)) {
        throw new DomainException('The Challenge privacy terms changed. Review the current terms before accepting.');
    }

    $membership = $pdo->prepare(
        'SELECT id,role_code,membership_status FROM crew_memberships WHERE crew_id=:crew_id AND user_id=:user_id LIMIT 1 FOR UPDATE'
    );
    $membership->execute([':crew_id' => (int) $intent['crew_id'], ':user_id' => $actorUserId]);
    $existingMembership = $membership->fetch(PDO::FETCH_ASSOC);
    if ($existingMembership === false) {
        $pdo->prepare(
            'INSERT INTO crew_memberships (crew_id,user_id,role_code,membership_status) VALUES (:crew_id,:user_id,\'MEMBER\',\'ACTIVE\')'
        )->execute([':crew_id' => (int) $intent['crew_id'], ':user_id' => $actorUserId]);
    } elseif ((string) $existingMembership['membership_status'] !== 'ACTIVE') {
        $pdo->prepare(
            'UPDATE crew_memberships SET membership_status=\'ACTIVE\',left_at=NULL,removed_at=NULL,joined_at=CURRENT_TIMESTAMP(6) WHERE id=:id'
        )->execute([':id' => (int) $existingMembership['id']]);
    }

    $before = fc_challenge_participation_for_user($pdo, (int) $intent['challenge_id'], $actorUserId);
    $alreadyEnrolled = $before !== null && (string) $before['participation_status'] === 'ACTIVE';

    fc_challenge_accept_participation_locked(
        $pdo,
        $actorUserId,
        (int) $intent['challenge_id'],
        (int) $intent['rule_version_id'],
        true,
        [
            'measurements_visibility' => (string) $intent['measurements_visibility'],
            'progress_visibility' => (string) $intent['progress_visibility'],
        ],
        null,
        'PERSONAL_ACCEPTANCE',
        true // A fresh, locked Challenge-scoped invitation explicitly authorizes re-entry after prior removal.
    );

    $accepted = $pdo->prepare(
        'UPDATE crew_invitations SET invitation_status=\'ACCEPTED\',accepted_by_user_id=:user_id,accepted_at=CURRENT_TIMESTAMP(6) ' .
        'WHERE id=:id AND invitation_status=\'PENDING\' AND resend_count=:generation'
    );
    $accepted->execute([
        ':user_id' => $actorUserId,
        ':id' => (int) $intent['invitation_id'],
        ':generation' => (int) $intent['invitation_generation'],
    ]);
    if ($accepted->rowCount() !== 1) throw new DomainException('This invitation changed before enrollment completed.');

    $consumeIntent = $pdo->prepare(
        'UPDATE challenge_invitation_acceptance_intents SET intent_status=\'CONSUMED\',consumed_at=CURRENT_TIMESTAMP(6) ' .
        'WHERE id=:id AND intent_status=\'PENDING\''
    );
    $consumeIntent->execute([':id' => (int) $intent['id']]);
    if ($consumeIntent->rowCount() !== 1) throw new DomainException('This Challenge acceptance was already used.');

    if ($consumeAuthContinuation) {
        if (!fc_auth_crew_invitation_continuation_consume(
            $pdo,
            (string) $invitation['invitation_public_id'],
            (int) $intent['invitation_generation']
        )) {
            throw new DomainException('Authentication continuation could not be completed. No enrollment was saved.');
        }
    }

    fc_product_context_persist($pdo, $actorUserId, (int) $intent['crew_id'], (int) $intent['challenge_id']);

    return [
        'crew_id' => (int) $intent['crew_id'],
        'challenge_id' => (int) $intent['challenge_id'],
        'already_enrolled' => $alreadyEnrolled,
    ];
}

function fc_challenge_invitation_intent_invitation_public_id(PDO $pdo, int $invitationId): string
{
    $q = $pdo->prepare('SELECT public_id FROM crew_invitations WHERE id=:id LIMIT 1');
    $q->execute([':id' => $invitationId]);
    $value = $q->fetchColumn();
    if ($value === false) throw new DomainException('Invitation is unavailable.');
    return (string) $value;
}
