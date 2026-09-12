<?php

declare(strict_types=1);

const FC_FAMILY_ALPHA_CONTRACT = 'FAMILY_ALPHA_PARTICIPATION_V1';
const FC_PARTICIPANT_VISIBILITIES = ['PRIVATE', 'CHALLENGE'];

/** Serialize product writers within a Crew; always call before Challenge/participant locks. */
function fc_family_lock_crew(PDO $pdo, int $crewId): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Product write lock requires an active transaction.');
    }
    $query = $pdo->prepare('SELECT id FROM crews WHERE id = :id FOR UPDATE');
    $query->execute([':id' => $crewId]);
    if ($query->fetchColumn() === false) {
        fc_product_access_denied('Crew is unavailable.');
    }
}

/** @return array<string,mixed> */
function fc_family_lock_challenge(PDO $pdo, int $challengeId): array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Challenge write lock requires an active transaction.');
    }
    $lookup = $pdo->prepare('SELECT crew_id FROM challenges WHERE id = :id');
    $lookup->execute([':id' => $challengeId]);
    $crewId = $lookup->fetchColumn();
    if ($crewId === false) {
        fc_product_access_denied('Challenge is unavailable.');
    }
    fc_family_lock_crew($pdo, (int) $crewId);
    $query = $pdo->prepare('SELECT * FROM challenges WHERE id = :id FOR UPDATE');
    $query->execute([':id' => $challengeId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if ($row === false || (int) $row['crew_id'] !== (int) $crewId) {
        fc_product_access_denied('Challenge is unavailable.');
    }
    return $row;
}

/** Strictly audit product transitions; never pass tokens, email destinations, or health payloads. */
function fc_family_event(PDO $pdo, int $challengeId, int $actorUserId, string $code, ?int $subjectUserId = null, array $details = []): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Product history must be written with its state change.');
    }
    $allowed = [
        'CHALLENGE_RENAMED', 'CHALLENGE_ENDED', 'CHALLENGE_ARCHIVED', 'CHALLENGE_UNARCHIVED',
        'CHALLENGE_DELETED', 'PARTICIPANT_INVITED', 'INVITATION_CANCELLED', 'INVITATION_DECLINED',
        'CONTRACT_ACCEPTED', 'PARTICIPANT_JOINED', 'PARTICIPANT_WITHDRAWN', 'PARTICIPANT_REMOVED',
        'PRIVACY_UPDATED',
    ];
    if (!in_array($code, $allowed, true)) {
        throw new InvalidArgumentException('Unknown product event.');
    }
    $write = $pdo->prepare(
        'INSERT INTO challenge_product_events (challenge_id,actor_user_id,subject_user_id,event_code,details_json) ' .
        'VALUES (:challenge,:actor,:subject,:code,:details)'
    );
    $write->execute([
        ':challenge' => $challengeId, ':actor' => $actorUserId, ':subject' => $subjectUserId,
        ':code' => $code, ':details' => json_encode($details, JSON_THROW_ON_ERROR),
    ]);
}

/** No permission is granted by resolving an identifier. Every caller must then authorize its use. */
function fc_family_challenge_id(PDO $pdo, string $publicId): int
{
    $query = $pdo->prepare('SELECT id FROM challenges WHERE public_id = :public_id');
    $query->execute([':public_id' => trim($publicId)]);
    $id = $query->fetchColumn();
    if ($id === false) {
        fc_product_access_denied('Challenge is unavailable.');
    }
    return (int) $id;
}

/** @return array<string,mixed> */
function fc_challenge_management_state(PDO $pdo, int $challengeId): array
{
    $query = $pdo->prepare('SELECT * FROM challenge_owner_controls WHERE challenge_id = :id' . fc_product_current_read($pdo));
    $query->execute([':id' => $challengeId]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: [
        'challenge_id' => $challengeId, 'effective_end_at' => null, 'ended_by_user_id' => null,
        'end_reason' => null, 'archived_at' => null, 'archived_by_user_id' => null,
        'deleted_at' => null, 'deleted_by_user_id' => null, 'revision' => 0,
    ];
}

function fc_challenge_management_label(array $state): string
{
    if ($state['deleted_at'] !== null) return 'Deleted from active use';
    if ($state['archived_at'] !== null) return 'Archived';
    if ($state['effective_end_at'] !== null) return 'Ended';
    return 'Current';
}

/** Owner intent is recorded independently from scoring/lifecycle finalization. */
function fc_challenge_manage(PDO $pdo, int $actorUserId, int $challengeId, string $action, array $input = []): void
{
    fc_product_atomic($pdo, function () use ($pdo, $actorUserId, $challengeId, $action, $input): void {
        fc_family_lock_challenge($pdo, $challengeId);
        $challenge = fc_challenge_require_owner($pdo, $actorUserId, $challengeId);
        $state = fc_challenge_management_state($pdo, $challengeId);
        if ($action === 'rename') {
            $name = trim((string) ($input['display_name'] ?? ''));
            if ($name === '' || strlen($name) > 140) throw new InvalidArgumentException('Enter a Challenge name of 140 characters or fewer.');
            if ($name === (string) $challenge['display_name']) return;
            fc_family_require_revision($state, $input);
            $pdo->prepare('INSERT IGNORE INTO challenge_owner_controls (challenge_id) VALUES (:id)')->execute([':id'=>$challengeId]);
            $pdo->prepare('UPDATE challenge_owner_controls SET revision=revision+1 WHERE challenge_id=:id')->execute([':id'=>$challengeId]);
            $q = $pdo->prepare('UPDATE challenges SET display_name = :name WHERE id = :id');
            $q->execute([':name' => $name, ':id' => $challengeId]);
            fc_family_event($pdo, $challengeId, $actorUserId, 'CHALLENGE_RENAMED', null, [
                'before' => $challenge['display_name'], 'after' => $name,
            ]);
            return;
        }
        if (!in_array($action, ['end', 'archive', 'unarchive', 'delete'], true)) throw new InvalidArgumentException('Unknown Challenge action.');
        $reason = trim((string) ($input['reason'] ?? ''));
        if (strlen($reason) > 500) throw new InvalidArgumentException('Keep the reason to 500 characters or fewer.');
        if ($action === 'end' && $state['effective_end_at'] !== null) return;
        if ($action === 'archive' && $state['archived_at'] !== null) return;
        if ($action === 'unarchive' && $state['archived_at'] === null) return;
        if ($action === 'delete' && $state['deleted_at'] !== null) return;
        fc_family_require_revision($state, $input);
        // This interface uses history-preserving deletion for all Challenges.
        // The existing pristine-Draft service remains the only physical deletion path.
        $pdo->prepare('INSERT IGNORE INTO challenge_owner_controls (challenge_id) VALUES (:id)')->execute([':id' => $challengeId]);
        $sets = [
            'end' => 'effective_end_at = CURRENT_TIMESTAMP(6), ended_by_user_id = :actor, end_reason = :reason',
            'archive' => 'archived_at = CURRENT_TIMESTAMP(6), archived_by_user_id = :actor',
            'unarchive' => 'archived_at = NULL, archived_by_user_id = NULL',
            'delete' => 'deleted_at = CURRENT_TIMESTAMP(6), deleted_by_user_id = :actor',
        ];
        $params = [':id' => $challengeId];
        if ($action !== 'unarchive') $params[':actor'] = $actorUserId;
        if ($action === 'end') $params[':reason'] = $reason === '' ? null : $reason;
        $q = $pdo->prepare('UPDATE challenge_owner_controls SET ' . $sets[$action] . ', revision = revision + 1 WHERE challenge_id = :id');
        $q->execute($params);
        $events = ['end'=>'CHALLENGE_ENDED','archive'=>'CHALLENGE_ARCHIVED','unarchive'=>'CHALLENGE_UNARCHIVED','delete'=>'CHALLENGE_DELETED'];
        fc_family_event($pdo, $challengeId, $actorUserId, $events[$action], null,
            $action === 'end' ? ['reason' => $reason, 'competitive_consequence' => 'NOT_DETERMINED'] : []);
        if (in_array($action, ['archive', 'delete'], true)) {
            $pdo->prepare('UPDATE user_product_contexts SET selected_challenge_id = NULL WHERE selected_challenge_id = :id')->execute([':id' => $challengeId]);
        }
    });
}

/** @return array{measurements_visibility:string,progress_visibility:string} */
function fc_challenge_privacy_for_user(PDO $pdo, int $challengeId, int $userId): array
{
    $q = $pdo->prepare('SELECT measurements_visibility, progress_visibility FROM challenge_privacy_preferences WHERE challenge_id = :c AND user_id = :u' . fc_product_current_read($pdo));
    $q->execute([':c'=>$challengeId, ':u'=>$userId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: ['measurements_visibility'=>'PRIVATE','progress_visibility'=>'PRIVATE'];
}

/** There is deliberately no target-user argument: a caller can change only its authenticated user's choices. */
function fc_challenge_privacy_save(PDO $pdo, int $actorUserId, int $challengeId, array $input): void
{
    fc_product_atomic($pdo, function () use ($pdo, $actorUserId, $challengeId, $input): void {
        fc_family_lock_challenge($pdo, $challengeId);
        fc_challenge_personal_context($pdo, $actorUserId, $challengeId);
        $values = fc_challenge_privacy_validate($input);
        $before = fc_challenge_privacy_for_user($pdo, $challengeId, $actorUserId);
        $q = $pdo->prepare(
            'INSERT INTO challenge_privacy_preferences (challenge_id,user_id,measurements_visibility,progress_visibility) VALUES (:c,:u,:m,:p) ' .
            'ON DUPLICATE KEY UPDATE measurements_visibility=VALUES(measurements_visibility),progress_visibility=VALUES(progress_visibility)'
        );
        $q->execute([':c'=>$challengeId, ':u'=>$actorUserId, ':m'=>$values['measurements_visibility'], ':p'=>$values['progress_visibility']]);
        if ($before !== $values) fc_family_event($pdo, $challengeId, $actorUserId, 'PRIVACY_UPDATED', $actorUserId, ['before'=>$before,'after'=>$values]);
    });
}

function fc_challenge_privacy_validate(array $input): array
{
    return [
        'measurements_visibility'=>fc_product_contract_value((string)($input['measurements_visibility'] ?? 'PRIVATE'), FC_PARTICIPANT_VISIBILITIES, 'measurement visibility'),
        'progress_visibility'=>fc_product_contract_value((string)($input['progress_visibility'] ?? 'PRIVATE'), FC_PARTICIPANT_VISIBILITIES, 'progress visibility'),
    ];
}

/** Minimal view for personal acceptance/withdrawal; no active roster, scores, or other users' measurements. */
function fc_challenge_personal_context(PDO $pdo, int $userId, int $challengeId): array
{
    $q = $pdo->prepare('SELECT c.id,c.public_id,c.crew_id,c.owner_user_id,c.display_name,c.lifecycle_status,c.operational_state,cr.display_name AS crew_name FROM challenges c JOIN crews cr ON cr.id=c.crew_id WHERE c.id=:id');
    $q->execute([':id'=>$challengeId]);
    $challenge = $q->fetch(PDO::FETCH_ASSOC);
    if ($challenge === false) fc_product_access_denied('Challenge is unavailable.');
    $p = fc_challenge_participation_for_user($pdo, $challengeId, $userId);
    $offer = fc_challenge_offer_for_user($pdo, $challengeId, $userId);
    $crewMember = false;
    try { fc_crew_require_member($pdo, $userId, (int)$challenge['crew_id']); $crewMember = true; } catch (DomainException) { }
    if (!$crewMember && $p === null && $offer === null && (int)$challenge['owner_user_id'] !== $userId) fc_product_access_denied('Challenge is unavailable.');
    return [
        'challenge'=>$challenge, 'participation'=>$p, 'offer'=>$offer, 'is_crew_member'=>$crewMember,
        'rule'=>fc_challenge_rule_current_published($pdo, $challengeId),
        'management'=>fc_challenge_management_state($pdo, $challengeId),
        'privacy'=>fc_challenge_privacy_for_user($pdo, $challengeId, $userId),
    ];
}

/** @return array<string,mixed>|null */
function fc_challenge_offer_for_user(PDO $pdo, int $challengeId, int $userId): ?array
{
    $q=$pdo->prepare('SELECT * FROM challenge_participant_offers WHERE challenge_id=:c AND invited_user_id=:u' . fc_product_current_read($pdo));
    $q->execute([':c'=>$challengeId,':u'=>$userId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Owner invitation creates an offer, never active participation or personal consent. */
function fc_challenge_offer_participation(PDO $pdo, int $actorUserId, int $challengeId, string $memberPublicId): void
{
    fc_product_atomic($pdo, function () use ($pdo,$actorUserId,$challengeId,$memberPublicId): void {
        fc_family_lock_challenge($pdo,$challengeId);
        $c=fc_challenge_require_owner($pdo,$actorUserId,$challengeId);
        $q=$pdo->prepare('SELECT m.user_id FROM crew_memberships m JOIN users u ON u.id=m.user_id WHERE m.crew_id=:c AND u.public_id=:p AND m.membership_status=\'ACTIVE\' AND u.account_status=\'ACTIVE\' FOR UPDATE');
        $q->execute([':c'=>(int)$c['crew_id'],':p'=>trim($memberPublicId)]);
        $member=$q->fetchColumn();
        if ($member === false) throw new DomainException('Choose a current member of this Crew.');
        $member=(int)$member;
        $p=fc_challenge_participation_for_user($pdo,$challengeId,$member);
        if ($p !== null && $p['participation_status'] === 'ACTIVE') return;
        $old=fc_challenge_offer_for_user($pdo,$challengeId,$member);
        if ($old !== null && $old['offer_status'] === 'PENDING') return;
        $q=$pdo->prepare('INSERT INTO challenge_participant_offers (public_id,challenge_id,invited_user_id,invited_by_user_id) VALUES (:public,:c,:u,:a) ON DUPLICATE KEY UPDATE public_id=VALUES(public_id),invited_by_user_id=VALUES(invited_by_user_id),offer_status=\'PENDING\',offered_at=CURRENT_TIMESTAMP(6),decided_at=NULL');
        $q->execute([':public'=>fc_new_public_id(),':c'=>$challengeId,':u'=>$member,':a'=>$actorUserId]);
        fc_family_event($pdo,$challengeId,$actorUserId,'PARTICIPANT_INVITED',$member);
    });
}

function fc_challenge_offer_decide(PDO $pdo,int $actorUserId,int $challengeId,string $action,?string $offerPublicId=null): void
{
    fc_product_atomic($pdo,function () use ($pdo,$actorUserId,$challengeId,$action,$offerPublicId): void {
        fc_family_lock_challenge($pdo,$challengeId);
        if ($action === 'cancel') {
            fc_challenge_require_owner($pdo,$actorUserId,$challengeId);
            $q=$pdo->prepare('SELECT * FROM challenge_participant_offers WHERE challenge_id=:c AND public_id=:p' . fc_product_current_read($pdo));
            $q->execute([':c'=>$challengeId,':p'=>$offerPublicId]); $offer=$q->fetch(PDO::FETCH_ASSOC);
        } elseif ($action === 'decline') {
            $offer=fc_challenge_offer_for_user($pdo,$challengeId,$actorUserId);
            if ($offer && !hash_equals((string)$offer['public_id'], (string)$offerPublicId)) throw new DomainException('This invitation changed. Review the latest invitation.');
        } else throw new InvalidArgumentException('Unknown invitation action.');
        if (!$offer || $offer['offer_status'] !== 'PENDING') throw new DomainException('This invitation is no longer pending.');
        $q=$pdo->prepare('UPDATE challenge_participant_offers SET offer_status=:s,decided_at=CURRENT_TIMESTAMP(6) WHERE id=:id');
        $q->execute([':s'=>$action === 'cancel' ? 'CANCELLED' : 'DECLINED',':id'=>(int)$offer['id']]);
        fc_family_event($pdo,$challengeId,$actorUserId,$action === 'cancel' ? 'INVITATION_CANCELLED' : 'INVITATION_DECLINED',(int)$offer['invited_user_id']);
    });
}

/** A late label records timing only; it is not a Scoring eligibility decision. */
function fc_family_entry_kind(array $challenge,array $publishedRule,DateTimeImmutable $now): string
{
    $start=new DateTimeImmutable((string)$publishedRule['planned_start_date'].' 00:00:00',new DateTimeZone((string)$publishedRule['challenge_timezone']));
    return $now >= $start ? 'LATE' : 'STANDARD';
}

/** Only explicit authenticated acceptance can make a new participation ACTIVE. */
function fc_challenge_accept_participation(PDO $pdo,int $actorUserId,int $challengeId,int $expectedRuleId,bool $accepted,array $privacy,?string $expectedOfferPublicId = null): void
{
    fc_product_atomic($pdo,function () use ($pdo,$actorUserId,$challengeId,$expectedRuleId,$accepted,$privacy,$expectedOfferPublicId): void {
        $challenge=fc_family_lock_challenge($pdo,$challengeId);
        fc_crew_require_member($pdo,$actorUserId,(int)$challenge['crew_id']);
        if (!$accepted) throw new DomainException('Please accept the Challenge Rules and competition-results visibility before joining.');
        $rule=fc_challenge_rule_current_published($pdo,$challengeId);
        if ($rule === null) throw new DomainException('The Owner needs to publish the Rules before you can accept this Challenge.');
        if ((int)$rule['id'] !== $expectedRuleId) throw new DomainException('The Rules changed while you were reviewing them. Review the current version before accepting.');
        $choices=fc_challenge_privacy_validate($privacy);
        $existing=fc_challenge_participation_for_user($pdo,$challengeId,$actorUserId);
        $offer=fc_challenge_offer_for_user($pdo,$challengeId,$actorUserId);
        if ($offer !== null && $offer['offer_status'] === 'PENDING' && !hash_equals((string)$offer['public_id'], (string)$expectedOfferPublicId)) throw new DomainException('This invitation changed. Open the latest invitation before accepting.');
        if ($existing !== null && $existing['participation_status'] === 'REMOVED' && (!$offer || $offer['offer_status'] !== 'PENDING')) {
            throw new DomainException('Ask the Owner to invite you again after removal.');
        }
        // A cancelled/declined owner offer cannot be claimed by replaying an old acceptance form.
        if ($offer !== null && in_array($offer['offer_status'],['CANCELLED','DECLINED'],true)) throw new DomainException('This invitation is no longer pending. Ask the Owner for a new invitation.');
        $q=$pdo->prepare('SELECT id,rule_version_id FROM challenge_acceptance_records WHERE challenge_id=:c AND user_id=:u ORDER BY id DESC LIMIT 1' . fc_product_current_read($pdo));
        $q->execute([':c'=>$challengeId,':u'=>$actorUserId]); $last=$q->fetch(PDO::FETCH_ASSOC);
        if ($existing !== null && $existing['participation_status'] === 'ACTIVE' && $last && (int)$last['rule_version_id'] === $expectedRuleId) {
            // Repeated submission is idempotent; privacy changes use the separate personal control.
            return;
        }
        $q=$pdo->prepare('INSERT INTO challenge_acceptance_records (challenge_id,user_id,rule_version_id,contract_code,measurements_visibility,progress_visibility) VALUES (:c,:u,:r,:contract,:m,:p)');
        $q->execute([':c'=>$challengeId,':u'=>$actorUserId,':r'=>$expectedRuleId,':contract'=>FC_FAMILY_ALPHA_CONTRACT,':m'=>$choices['measurements_visibility'],':p'=>$choices['progress_visibility']]);
        $acceptanceId=(int)$pdo->lastInsertId();
        fc_family_event($pdo,$challengeId,$actorUserId,'CONTRACT_ACCEPTED',$actorUserId,['rule_version_id'=>$expectedRuleId,'contract_code'=>FC_FAMILY_ALPHA_CONTRACT]);
        fc_challenge_privacy_save($pdo,$actorUserId,$challengeId,$choices);
        if ($existing === null || $existing['participation_status'] !== 'ACTIVE') {
            $entry=fc_family_entry_kind($challenge,$rule,new DateTimeImmutable('now',new DateTimeZone('UTC')));
            $q=$pdo->prepare('INSERT INTO challenge_participations (challenge_id,user_id,participation_status,entry_kind) VALUES (:c,:u,\'ACTIVE\',:e) ON DUPLICATE KEY UPDATE participation_status=\'ACTIVE\',entry_kind=VALUES(entry_kind),withdrawn_at=NULL,removed_at=NULL');
            $q->execute([':c'=>$challengeId,':u'=>$actorUserId,':e'=>$entry]);
            $q=$pdo->prepare('INSERT INTO challenge_participation_intervals (challenge_id,user_id,entered_at,entry_source,acceptance_record_id) VALUES (:c,:u,CURRENT_TIMESTAMP(6),\'PERSONAL_ACCEPTANCE\',:a)');
            $q->execute([':c'=>$challengeId,':u'=>$actorUserId,':a'=>$acceptanceId]);
            fc_family_event($pdo,$challengeId,$actorUserId,'PARTICIPANT_JOINED',$actorUserId,['entry_kind'=>$entry,'scoring_eligibility'=>'NOT_DETERMINED']);
        }
        $q=$pdo->prepare('UPDATE challenge_participant_offers SET offer_status=\'ACCEPTED\',decided_at=CURRENT_TIMESTAMP(6) WHERE challenge_id=:c AND invited_user_id=:u AND offer_status=\'PENDING\'');
        $q->execute([':c'=>$challengeId,':u'=>$actorUserId]);
    });
}

/** Active interval closes exactly once; historical intervals and original joined_at survive rejoining. */
function fc_challenge_exit_participation(PDO $pdo,int $actorUserId,int $challengeId,int $subjectUserId,string $status): void
{
    fc_product_atomic($pdo,function () use ($pdo,$actorUserId,$challengeId,$subjectUserId,$status): void {
        fc_family_lock_challenge($pdo,$challengeId);
        if ($status === 'WITHDRAWN') {
            if ($subjectUserId !== $actorUserId) fc_product_access_denied('Only you can submit your personal withdrawal.');
        } elseif ($status === 'REMOVED') {
            fc_challenge_require_owner($pdo,$actorUserId,$challengeId);
        } else throw new InvalidArgumentException('Unknown participation exit.');
        $p=fc_challenge_participation_for_user($pdo,$challengeId,$subjectUserId);
        if ($p === null) throw new DomainException('There is no participation to change.');
        if ($p['participation_status'] === $status) return;
        if ($p['participation_status'] !== 'ACTIVE') throw new DomainException('This participation is not active.');
        $timeColumn=$status === 'WITHDRAWN' ? 'withdrawn_at' : 'removed_at';
        $q=$pdo->prepare('UPDATE challenge_participations SET participation_status=:s,'.$timeColumn.'=CURRENT_TIMESTAMP(6) WHERE id=:id');
        $q->execute([':s'=>$status,':id'=>(int)$p['id']]);
        fc_family_close_interval($pdo,$challengeId,$subjectUserId,$status);
        fc_family_event($pdo,$challengeId,$actorUserId,$status === 'WITHDRAWN' ? 'PARTICIPANT_WITHDRAWN' : 'PARTICIPANT_REMOVED',$subjectUserId);
    });
}

function fc_family_close_interval(PDO $pdo,int $challengeId,int $subjectUserId,string $status): void
{
    $q=$pdo->prepare('UPDATE challenge_participation_intervals i JOIN challenge_participations p ON p.challenge_id=i.challenge_id AND p.user_id=i.user_id SET i.exited_at=CASE WHEN p.participation_status=\'WITHDRAWN\' THEN p.withdrawn_at ELSE p.removed_at END,i.exit_status=:s WHERE i.challenge_id=:c AND i.user_id=:u AND i.exited_at IS NULL AND i.exit_status IS NULL');
    $q->execute([':s'=>$status,':c'=>$challengeId,':u'=>$subjectUserId]);
}

/** Own inbox and historical access are independent of selected context and current Crew membership. */
function fc_challenge_personal_list(PDO $pdo,int $userId): array
{
    $q=$pdo->prepare('SELECT c.id,c.public_id,c.display_name,cr.display_name AS crew_name,p.participation_status,o.offer_status,ctl.effective_end_at,ctl.archived_at,ctl.deleted_at FROM challenges c JOIN crews cr ON cr.id=c.crew_id LEFT JOIN challenge_participations p ON p.challenge_id=c.id AND p.user_id=:puser LEFT JOIN challenge_participant_offers o ON o.challenge_id=c.id AND o.invited_user_id=:ouser LEFT JOIN challenge_owner_controls ctl ON ctl.challenge_id=c.id WHERE p.id IS NOT NULL OR o.id IS NOT NULL ORDER BY c.id DESC');
    $q->execute([':puser'=>$userId,':ouser'=>$userId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Owner-only pending list; pending users are never included in the active roster. */
function fc_challenge_pending_offers(PDO $pdo,int $actorUserId,int $challengeId): array
{
    fc_challenge_require_owner($pdo,$actorUserId,$challengeId);
    $q=$pdo->prepare('SELECT o.public_id,o.offered_at,u.display_name FROM challenge_participant_offers o JOIN users u ON u.id=o.invited_user_id WHERE o.challenge_id=:c AND o.offer_status=\'PENDING\' ORDER BY o.id');
    $q->execute([':c'=>$challengeId]);return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Category policy for future qualified data readers. Raw provider data is never in the ordinary audience. */
function fc_challenge_disclosure_policy(PDO $pdo,int $viewerUserId,int $challengeId,int $subjectUserId): array
{
    $subject=fc_challenge_participation_for_user($pdo,$challengeId,$subjectUserId);
    if ($subject === null) fc_product_access_denied('Participant is unavailable.');
    if ($viewerUserId === $subjectUserId) {
        return ['competition_results'=>true,'official_measurements'=>true,'personal_progress'=>true,'raw_provider_data'=>false];
    }
    $challenge=fc_challenge_require_access($pdo,$viewerUserId,$challengeId);
    $viewer=fc_challenge_participation_for_user($pdo,$challengeId,$viewerUserId);
    $audience=(int)$challenge['owner_user_id'] === $viewerUserId || ($viewer !== null && $viewer['participation_status'] === 'ACTIVE');
    if (!$audience || $subject['participation_status'] !== 'ACTIVE') {
        return ['competition_results'=>false,'official_measurements'=>false,'personal_progress'=>false,'raw_provider_data'=>false];
    }
    $privacy=fc_challenge_privacy_for_user($pdo,$challengeId,$subjectUserId);
    return [
        'competition_results'=>true,
        'official_measurements'=>$privacy['measurements_visibility'] === 'CHALLENGE',
        'personal_progress'=>$privacy['progress_visibility'] === 'CHALLENGE',
        'raw_provider_data'=>false,
    ];
}

/** Current production read model is intentionally field-allowlisted and contains no health/score fields. */
function fc_challenge_public_participant_cards(PDO $pdo,int $viewerUserId,int $challengeId): array
{
    $challenge=fc_challenge_require_access($pdo,$viewerUserId,$challengeId);
    $viewer=fc_challenge_participation_for_user($pdo,$challengeId,$viewerUserId);
    if ((int)$challenge['owner_user_id'] !== $viewerUserId && ($viewer === null || $viewer['participation_status'] !== 'ACTIVE')) return [];
    $q=$pdo->prepare('SELECT u.public_id AS user_public_id,u.display_name,p.participation_status,p.entry_kind FROM challenge_participations p JOIN users u ON u.id=p.user_id WHERE p.challenge_id=:c ORDER BY p.joined_at,p.id');
    $q->execute([':c'=>$challengeId]);return $q->fetchAll(PDO::FETCH_ASSOC);
}

function fc_family_require_revision(array $state, array $input): void
{
    if (array_key_exists('expected_revision', $input) && (int)$input['expected_revision'] !== (int)$state['revision']) {
        throw new DomainException('The Challenge changed while this form was open. Review its current state before confirming.');
    }
}
