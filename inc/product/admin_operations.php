<?php

declare(strict_types=1);

/**
 * ADMIN-2B / ADMIN-2C Website/Product owner services.
 *
 * This layer never trusts an Admin surface to supply actor authority. Every call
 * resolves the current FitCrew session, rechecks the stored role and active
 * identity under lock, uses stale-state revisions + idempotency receipts, and
 * writes success audit atomically with the product mutation.
 */
const FC_PRODUCT_ADMIN_CREW_OPERATIONS = [
    'edit' => 'crew_edit',
    'transfer_owner' => 'crew_transfer_owner',
    'archive' => 'crew_archive',
    'restore' => 'crew_restore',
    'remove_member' => 'crew_remove_member',
];

const FC_PRODUCT_ADMIN_CHALLENGE_OPERATIONS = [
    'edit_name' => 'challenge_edit_name',
    'edit_rule_draft' => 'challenge_edit_rule_draft',
    'remove_participant' => 'challenge_remove_participant',
    'end' => 'challenge_end',
    'archive' => 'challenge_archive',
    'unarchive' => 'challenge_unarchive',
];

function fc_product_admin_query(PDO $pdo, string $sql, array $values = []): PDOStatement
{
    $statement = $pdo->prepare($sql);
    $statement->execute($values);
    return $statement;
}

function fc_product_admin_begin(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Product Admin operations own their transaction.');
    }
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->beginTransaction();
}

function fc_product_admin_text(mixed $value, int $max, string $field, bool $allowEmpty = false): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        throw new InvalidArgumentException('invalid_' . $field);
    }
    $value = trim($value);
    if ((!$allowEmpty && $value === '') || preg_match_all('/./us', $value) > $max || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
        throw new InvalidArgumentException('invalid_' . $field);
    }
    return $value;
}

function fc_product_admin_request(string $revision, string $requestKey, string $reason): array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $revision)) {
        throw new InvalidArgumentException('invalid_revision');
    }
    if (!preg_match('/\A[A-Za-z0-9_-]{16,80}\z/', $requestKey)) {
        throw new InvalidArgumentException('invalid_request_key');
    }
    return [$revision, $requestKey, fc_product_admin_text($reason, 500, 'reason')];
}

/** @return array<string,mixed> */
function fc_product_admin_actor(PDO $pdo): array
{
    if (session_id() === '') {
        throw new DomainException('product_admin_operation_denied');
    }
    $sessionHash = fc_session_id_hash(session_id());
    $statement = fc_product_admin_query($pdo,
        "SELECT u.id,u.public_id,u.display_name,u.account_status,u.platform_role_code,
                s.id AS session_record_id,s.idle_expires_at,s.absolute_expires_at
         FROM user_sessions s
         JOIN users u ON u.id=s.user_id
         JOIN user_auth_identities i ON i.id=s.auth_identity_id AND i.user_id=s.user_id
         WHERE s.session_id_hash=? AND s.revoked_at IS NULL
           AND s.idle_expires_at>UTC_TIMESTAMP(6) AND s.absolute_expires_at>UTC_TIMESTAMP(6)
           AND i.identity_status='ACTIVE'
         LIMIT 1 FOR UPDATE",
        [$sessionHash]
    );
    $actor = $statement->fetch(PDO::FETCH_ASSOC);
    if ($actor === false
        || (string)$actor['account_status'] !== 'ACTIVE'
        || !in_array((string)$actor['platform_role_code'], ['PLATFORM_ADMIN', 'PLATFORM_SUPER_ADMIN'], true)) {
        throw new DomainException('product_admin_operation_denied');
    }
    $now = (string)$pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn();
    if (min((string)$actor['idle_expires_at'], (string)$actor['absolute_expires_at']) <= $now) {
        throw new DomainException('product_admin_operation_denied');
    }
    return $actor;
}

function fc_product_admin_is_super(array $actor): bool
{
    return (string)$actor['platform_role_code'] === 'PLATFORM_SUPER_ADMIN';
}

/** @return array<string,mixed> */
function fc_product_admin_lock_crew_public(PDO $pdo, string $publicId): array
{
    if (!fc_public_id_is_valid($publicId)) {
        throw new DomainException('crew_operation_unavailable');
    }
    $statement = fc_product_admin_query($pdo, 'SELECT * FROM crews WHERE public_id=? FOR UPDATE', [$publicId]);
    $crew = $statement->fetch(PDO::FETCH_ASSOC);
    if ($crew === false) {
        throw new DomainException('crew_operation_unavailable');
    }
    return $crew;
}

/** @return array<string,mixed> */
function fc_product_admin_lock_challenge_public(PDO $pdo, string $publicId): array
{
    if (!fc_public_id_is_valid($publicId)) {
        throw new DomainException('challenge_operation_unavailable');
    }
    $lookup = fc_product_admin_query($pdo, 'SELECT id,crew_id FROM challenges WHERE public_id=?', [$publicId])->fetch(PDO::FETCH_ASSOC);
    if ($lookup === false) {
        throw new DomainException('challenge_operation_unavailable');
    }
    fc_family_lock_crew($pdo, (int)$lookup['crew_id']);
    $statement = fc_product_admin_query($pdo, 'SELECT * FROM challenges WHERE id=? FOR UPDATE', [(int)$lookup['id']]);
    $challenge = $statement->fetch(PDO::FETCH_ASSOC);
    if ($challenge === false) {
        throw new DomainException('challenge_operation_unavailable');
    }
    return $challenge;
}

/** @return list<array<string,mixed>> */
function fc_product_admin_crew_members(PDO $pdo, int $crewId): array
{
    return fc_product_admin_query($pdo,
        'SELECT m.id,m.user_id,m.role_code,m.membership_status,m.joined_at,m.left_at,m.removed_at,
                u.public_id AS user_public_id,u.display_name,u.account_status
         FROM crew_memberships m JOIN users u ON u.id=m.user_id
         WHERE m.crew_id=? ORDER BY m.id FOR UPDATE', [$crewId])->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string,mixed> */
function fc_product_admin_crew_state(PDO $pdo, array $crew): array
{
    $members = fc_product_admin_crew_members($pdo, (int)$crew['id']);
    $current = fc_product_admin_query($pdo,
        'SELECT cc.challenge_id,c.public_id,c.display_name,c.owner_user_id,c.lifecycle_status
         FROM crew_current_challenges cc JOIN challenges c ON c.id=cc.challenge_id
         WHERE cc.crew_id=? FOR UPDATE', [(int)$crew['id']])->fetch(PDO::FETCH_ASSOC);
    $profile = [
        'public_id'=>(string)$crew['public_id'], 'display_name'=>(string)$crew['display_name'],
        'description'=>$crew['description'], 'owner_user_id'=>(int)$crew['owner_user_id'],
        'crew_status'=>(string)$crew['crew_status'], 'archived_at'=>$crew['archived_at'], 'updated_at'=>$crew['updated_at'],
    ];
    $revision = hash('sha256', json_encode([$profile, $members, $current ?: null], JSON_THROW_ON_ERROR));
    return ['crew'=>$profile, 'members'=>$members, 'current_challenge'=>$current ?: null, 'revision'=>$revision];
}

/** @return array<string,mixed> */
function fc_product_admin_challenge_state(PDO $pdo, array $challenge): array
{
    $controls = fc_challenge_management_state($pdo, (int)$challenge['id']);
    $draft = fc_challenge_rule_current_draft($pdo, (int)$challenge['id']);
    $published = fc_challenge_rule_current_published($pdo, (int)$challenge['id']);
    $participants = fc_product_admin_query($pdo,
        'SELECT p.id,p.user_id,p.participation_status,p.entry_kind,p.joined_at,p.withdrawn_at,p.removed_at,
                u.public_id AS user_public_id,u.display_name
         FROM challenge_participations p JOIN users u ON u.id=p.user_id
         WHERE p.challenge_id=? ORDER BY p.id FOR UPDATE', [(int)$challenge['id']])->fetchAll(PDO::FETCH_ASSOC);
    $profile = [
        'public_id'=>(string)$challenge['public_id'], 'crew_id'=>(int)$challenge['crew_id'],
        'owner_user_id'=>(int)$challenge['owner_user_id'], 'display_name'=>(string)$challenge['display_name'],
        'lifecycle_status'=>(string)$challenge['lifecycle_status'], 'operational_state'=>(string)$challenge['operational_state'],
        'completed_at'=>$challenge['completed_at'], 'updated_at'=>$challenge['updated_at'],
    ];
    $revision = hash('sha256', json_encode([$profile, $controls, $draft, $published, $participants], JSON_THROW_ON_ERROR));
    return [
        'challenge'=>$profile, 'owner_controls'=>$controls, 'draft_rule'=>$draft, 'published_rule'=>$published,
        'participants'=>$participants, 'revision'=>$revision,
    ];
}

function fc_product_admin_crew_allowed(array $actor, array $crew): array
{
    $allowed = ['edit'];
    if (fc_product_admin_is_super($actor)) {
        $allowed[] = 'transfer_owner';
        $allowed[] = (string)$crew['crew_status'] === 'ARCHIVED' ? 'restore' : 'archive';
        $allowed[] = 'remove_member';
    }
    return $allowed;
}

function fc_product_admin_challenge_allowed(array $actor, array $challenge): array
{
    $allowed = ['edit_name', 'edit_rule_draft'];
    if (!fc_product_admin_is_super($actor) && !in_array((string)$challenge['lifecycle_status'], ['DRAFT','FORMING_CREW'], true)) {
        $allowed = [];
    }
    if (fc_product_admin_is_super($actor)) {
        $allowed = ['edit_name','edit_rule_draft','remove_participant','end','archive','unarchive'];
    }
    if ((string)$challenge['lifecycle_status'] === 'COMPLETED') {
        $allowed = array_values(array_intersect($allowed, ['edit_name','archive','unarchive']));
    }
    return $allowed;
}

/** Read model for Admin Crew editor. */
function fc_product_admin_crew_snapshot(PDO $pdo, string $crewPublicId): array
{
    fc_product_admin_begin($pdo);
    try {
        $actor = fc_product_admin_actor($pdo);
        $crew = fc_product_admin_lock_crew_public($pdo, $crewPublicId);
        $state = fc_product_admin_crew_state($pdo, $crew);
        $state['allowed_operations'] = fc_product_admin_crew_allowed($actor, $crew);
        $pdo->commit();
        return $state;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Read model for Admin Challenge editor. */
function fc_product_admin_challenge_snapshot(PDO $pdo, string $challengePublicId): array
{
    fc_product_admin_begin($pdo);
    try {
        $actor = fc_product_admin_actor($pdo);
        $challenge = fc_product_admin_lock_challenge_public($pdo, $challengePublicId);
        $state = fc_product_admin_challenge_state($pdo, $challenge);
        $state['allowed_operations'] = fc_product_admin_challenge_allowed($actor, $challenge);
        $pdo->commit();
        return $state;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function fc_product_admin_receipt(PDO $pdo, int $actorId, string $targetType, string $targetPublicId, string $requestKey): ?array
{
    $row = fc_product_admin_query($pdo,
        'SELECT request_digest,result_json FROM product_admin_operations WHERE actor_user_id=? AND request_key=? FOR UPDATE',
        [$actorId, $requestKey])->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function fc_product_admin_store_receipt(PDO $pdo, int $actorId, string $targetType, string $targetPublicId, string $requestKey, string $digest, string $operationKey, array $result): void
{
    fc_product_admin_query($pdo,
        'INSERT INTO product_admin_operations (actor_user_id,target_type,target_public_id,request_key,request_digest,operation_key,result_json)
         VALUES (?,?,?,?,?,?,?)',
        [$actorId,$targetType,$targetPublicId,$requestKey,$digest,$operationKey,json_encode($result, JSON_THROW_ON_ERROR)]);
}

function fc_product_admin_audit(PDO $pdo, array $actor, string $event, string $targetType, string $targetPublicId, int $crewId, string $reason, array $before, array $after, array $extra = [], string $outcome = 'SUCCESS'): int
{
    return fc_audit_event_write($pdo, [
        'actor_user_id'=>(int)$actor['id'], 'event_type'=>$event, 'target_type'=>$targetType,
        'target_id'=>$targetPublicId, 'outcome'=>$outcome, 'group_id'=>$crewId,
        'metadata'=>['reason'=>$reason,'before'=>$before,'after'=>$after] + $extra,
    ])['id'];
}

function fc_product_admin_denied_audit(PDO $pdo, ?array $actor, string $targetType, string $targetPublicId, int $crewId, string $operation, string $reason, Throwable $error): void
{
    if ($actor === null) return;
    try {
        fc_product_admin_audit($pdo, $actor, 'PRODUCT_ADMIN_OPERATION_REJECTED', $targetType, $targetPublicId, $crewId,
            $reason, [], [], ['operation'=>$operation,'failure'=>$error instanceof DomainException ? $error->getMessage() : 'operation_failed'],
            $error instanceof DomainException ? 'DENIED' : 'FAILURE');
    } catch (Throwable) {
        // Never replace the governed operation failure with best-effort denial-audit failure.
    }
}

function fc_product_admin_assert_revision(string $expected, string $actual, string $failure): void
{
    if (!hash_equals($actual, $expected)) throw new DomainException($failure);
}

function fc_product_admin_crew_mutate(PDO $pdo, string $crewPublicId, string $operation, array $fields, string $revision, string $requestKey, string $reason): array
{
    [$revision,$requestKey,$reason] = fc_product_admin_request($revision,$requestKey,$reason);
    if (!isset(FC_PRODUCT_ADMIN_CREW_OPERATIONS[$operation])) throw new InvalidArgumentException('invalid_operation');
    ksort($fields);
    $operationKey = FC_PRODUCT_ADMIN_CREW_OPERATIONS[$operation];
    $digest = hash('sha256', json_encode([$crewPublicId,$operation,$fields,$revision,$reason], JSON_THROW_ON_ERROR));
    $actor = null; $crewId = 0;
    fc_product_admin_begin($pdo);
    try {
        $actor = fc_product_admin_actor($pdo);
        $crew = fc_product_admin_lock_crew_public($pdo,$crewPublicId); $crewId=(int)$crew['id'];
        if (!in_array($operation, fc_product_admin_crew_allowed($actor,$crew), true)) throw new DomainException('crew_operation_denied');
        $prior = fc_product_admin_receipt($pdo,(int)$actor['id'],'CREW',$crewPublicId,$requestKey);
        if ($prior !== null) {
            if (!hash_equals((string)$prior['request_digest'],$digest)) throw new DomainException('idempotency_conflict');
            $result=json_decode((string)$prior['result_json'],true,512,JSON_THROW_ON_ERROR); $pdo->commit();
            return $result+['replayed'=>true];
        }
        $state=fc_product_admin_crew_state($pdo,$crew);
        fc_product_admin_assert_revision($revision,$state['revision'],'stale_crew_state');
        $before=$after=$extra=[];
        $event='ADMIN_CREW_OPERATION';

        if ($operation==='edit') {
            if (array_diff(array_keys($fields),['display_name','description'])!==[] || $fields===[]) throw new InvalidArgumentException('invalid_crew_fields');
            $name=array_key_exists('display_name',$fields) ? fc_product_admin_text($fields['display_name'],120,'display_name') : (string)$crew['display_name'];
            $description=array_key_exists('description',$fields) ? fc_product_admin_text((string)$fields['description'],500,'description',true) : (string)($crew['description']??'');
            $before=['display_name'=>$crew['display_name'],'description'=>$crew['description']];
            $after=['display_name'=>$name,'description'=>$description===''?null:$description];
            fc_product_admin_query($pdo,'UPDATE crews SET display_name=?,description=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?',[$name,$after['description'],$crewId]);
            $event='ADMIN_CREW_EDITED';
        } elseif ($operation==='transfer_owner') {
            if (array_keys($fields)!==['new_owner_public_id'] || !fc_public_id_is_valid((string)$fields['new_owner_public_id'])) throw new InvalidArgumentException('invalid_new_owner');
            $target=fc_product_admin_query($pdo,'SELECT id,public_id,display_name,account_status FROM users WHERE public_id=? FOR UPDATE',[(string)$fields['new_owner_public_id']])->fetch(PDO::FETCH_ASSOC);
            if ($target===false || (string)$target['account_status']!=='ACTIVE') throw new DomainException('new_owner_unavailable');
            $newOwnerId=(int)$target['id'];
            if ($newOwnerId===(int)$crew['owner_user_id']) throw new DomainException('owner_unchanged');
            $members=fc_product_admin_crew_members($pdo,$crewId); $newMembership=null; $oldMembership=null;
            foreach($members as $member){ if((int)$member['user_id']===$newOwnerId)$newMembership=$member; if((int)$member['user_id']===(int)$crew['owner_user_id'])$oldMembership=$member; }
            if($newMembership===null || (string)$newMembership['membership_status']!=='ACTIVE') throw new DomainException('new_owner_must_be_active_crew_member');
            if($oldMembership===null || (string)$oldMembership['membership_status']!=='ACTIVE' || (string)$oldMembership['role_code']!=='OWNER') throw new DomainException('crew_owner_membership_invalid');
            $oldOwnerId=(int)$crew['owner_user_id'];
            fc_product_admin_query($pdo,"UPDATE crew_memberships SET role_code='MEMBER',updated_at=UTC_TIMESTAMP(6) WHERE id=?",[(int)$oldMembership['id']]);
            fc_product_admin_query($pdo,"UPDATE crew_memberships SET role_code='OWNER',updated_at=UTC_TIMESTAMP(6) WHERE id=?",[(int)$newMembership['id']]);
            fc_product_admin_query($pdo,'UPDATE crews SET owner_user_id=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?',[$newOwnerId,$crewId]);
            $current=fc_product_admin_query($pdo,'SELECT challenge_id FROM crew_current_challenges WHERE crew_id=? FOR UPDATE',[$crewId])->fetchColumn();
            if($current!==false) fc_product_admin_query($pdo,'UPDATE challenges SET owner_user_id=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?',[$newOwnerId,(int)$current]);
            $before=['owner_user_id'=>$oldOwnerId]; $after=['owner_user_id'=>$newOwnerId];
            $extra=['new_owner_public_id'=>(string)$target['public_id'],'current_challenge_owner_updated'=>$current!==false];
            $event='ADMIN_CREW_OWNERSHIP_TRANSFERRED';
        } elseif ($operation==='archive') {
            if ($fields!==[]) throw new InvalidArgumentException('unexpected_fields');
            if ((string)$crew['crew_status']!=='ACTIVE') throw new DomainException('invalid_crew_transition');
            $current=fc_product_admin_query($pdo,'SELECT challenge_id FROM crew_current_challenges WHERE crew_id=? FOR UPDATE',[$crewId])->fetchColumn();
            if($current!==false) throw new DomainException('crew_has_current_challenge');
            $before=['crew_status'=>'ACTIVE']; $after=['crew_status'=>'ARCHIVED'];
            fc_product_admin_query($pdo,"UPDATE crews SET crew_status='ARCHIVED',archived_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?",[$crewId]);
            $event='ADMIN_CREW_ARCHIVED';
        } elseif ($operation==='restore') {
            if ($fields!==[]) throw new InvalidArgumentException('unexpected_fields');
            if ((string)$crew['crew_status']!=='ARCHIVED') throw new DomainException('invalid_crew_transition');
            $before=['crew_status'=>'ARCHIVED']; $after=['crew_status'=>'ACTIVE'];
            fc_product_admin_query($pdo,"UPDATE crews SET crew_status='ACTIVE',archived_at=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=?",[$crewId]);
            $event='ADMIN_CREW_RESTORED';
        } else {
            if (array_keys($fields)!==['member_public_id'] || !fc_public_id_is_valid((string)$fields['member_public_id'])) throw new InvalidArgumentException('invalid_member');
            $member=fc_product_admin_query($pdo,'SELECT id,public_id,display_name FROM users WHERE public_id=? FOR UPDATE',[(string)$fields['member_public_id']])->fetch(PDO::FETCH_ASSOC);
            if($member===false) throw new DomainException('crew_member_unavailable');
            $memberId=(int)$member['id'];
            if($memberId===(int)$crew['owner_user_id']) throw new DomainException('crew_owner_cannot_be_removed');
            $membership=fc_product_admin_query($pdo,'SELECT id,membership_status,role_code FROM crew_memberships WHERE crew_id=? AND user_id=? FOR UPDATE',[$crewId,$memberId])->fetch(PDO::FETCH_ASSOC);
            if($membership===false || (string)$membership['membership_status']!=='ACTIVE') throw new DomainException('crew_member_not_active');
            fc_product_admin_query($pdo,"UPDATE crew_memberships SET membership_status='REMOVED',removed_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?",[(int)$membership['id']]);
            $affected=fc_product_admin_query($pdo,"SELECT p.challenge_id FROM challenge_participations p JOIN challenges c ON c.id=p.challenge_id WHERE c.crew_id=? AND p.user_id=? AND p.participation_status='ACTIVE' ORDER BY p.challenge_id FOR UPDATE",[$crewId,$memberId])->fetchAll(PDO::FETCH_COLUMN);
            foreach($affected as $challengeId){
                fc_product_admin_query($pdo,"UPDATE challenge_participations SET participation_status='REMOVED',removed_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE challenge_id=? AND user_id=? AND participation_status='ACTIVE'",[(int)$challengeId,$memberId]);
                fc_family_close_interval($pdo,(int)$challengeId,$memberId,'REMOVED');
                fc_family_event($pdo,(int)$challengeId,(int)$actor['id'],'PARTICIPANT_REMOVED',$memberId,['source'=>'ADMIN_CREW_MEMBERSHIP_REMOVAL']);
            }
            fc_product_admin_query($pdo,"UPDATE challenge_participant_offers o JOIN challenges c ON c.id=o.challenge_id SET o.offer_status='CANCELLED',o.decided_at=UTC_TIMESTAMP(6) WHERE c.crew_id=? AND o.invited_user_id=? AND o.offer_status='PENDING'",[$crewId,$memberId]);
            fc_product_admin_query($pdo,'UPDATE user_product_contexts SET selected_crew_id=NULL,selected_challenge_id=NULL WHERE user_id=? AND selected_crew_id=?',[$memberId,$crewId]);
            $before=['member_public_id'=>(string)$member['public_id'],'membership_status'=>'ACTIVE']; $after=['member_public_id'=>(string)$member['public_id'],'membership_status'=>'REMOVED'];
            $extra=['removed_active_challenge_participations'=>count($affected)];
            $event='ADMIN_CREW_MEMBER_REMOVED';
        }

        // Recheck the privileged session after all potentially contended locks.
        fc_product_admin_actor($pdo);
        $audit=fc_product_admin_audit($pdo,$actor,$event,'CREW',$crewPublicId,$crewId,$reason,$before,$after,$extra);
        $result=['operation'=>$operation,'target_public_id'=>$crewPublicId,'audit_id'=>$audit]+$extra;
        fc_product_admin_store_receipt($pdo,(int)$actor['id'],'CREW',$crewPublicId,$requestKey,$digest,$operationKey,$result);
        $pdo->commit();
        return $result+['replayed'=>false];
    } catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        fc_product_admin_denied_audit($pdo,$actor,'CREW',$crewPublicId,$crewId,$operation,$reason,$e);
        throw $e;
    }
}

function fc_product_admin_rule_values(array $source, array $fields): array
{
    $allowed=['planned_start_date','planned_end_date','duration_days','challenge_timezone','weekly_checkin_day','live_leaderboard_visible'];
    if(array_diff(array_keys($fields),$allowed)!==[] || $fields===[]) throw new InvalidArgumentException('invalid_rule_fields');
    $plannedStart=array_key_exists('planned_start_date',$fields) ? fc_rule_date_or_null($fields['planned_start_date']) : ($source['planned_start_date'] ?? null);
    $plannedEnd=$fields['planned_end_date'] ?? null;
    $duration=fc_rule_duration_days_resolve($plannedStart,$plannedEnd,$fields['duration_days'] ?? ($source['duration_days'] ?? 84),(int)($source['duration_days'] ?? 84));
    $timezone=array_key_exists('challenge_timezone',$fields) ? trim((string)$fields['challenge_timezone']) : (string)$source['challenge_timezone'];
    if(!fc_product_timezone_is_valid($timezone)) throw new InvalidArgumentException('invalid_challenge_timezone');
    $checkin=array_key_exists('weekly_checkin_day',$fields) ? (int)$fields['weekly_checkin_day'] : (int)$source['weekly_checkin_day'];
    if($checkin<0 || $checkin>6) throw new InvalidArgumentException('invalid_weekly_checkin_day');
    $visible=array_key_exists('live_leaderboard_visible',$fields) ? (bool)$fields['live_leaderboard_visible'] : (bool)$source['live_leaderboard_visible'];
    return ['planned_start_date'=>$plannedStart,'duration_days'=>$duration,'challenge_timezone'=>$timezone,'weekly_checkin_day'=>$checkin,'live_leaderboard_visible'=>$visible?1:0];
}

function fc_product_admin_challenge_mutate(PDO $pdo, string $challengePublicId, string $operation, array $fields, string $revision, string $requestKey, string $reason): array
{
    [$revision,$requestKey,$reason]=fc_product_admin_request($revision,$requestKey,$reason);
    if(!isset(FC_PRODUCT_ADMIN_CHALLENGE_OPERATIONS[$operation])) throw new InvalidArgumentException('invalid_operation');
    ksort($fields);
    $operationKey=FC_PRODUCT_ADMIN_CHALLENGE_OPERATIONS[$operation];
    $digest=hash('sha256',json_encode([$challengePublicId,$operation,$fields,$revision,$reason],JSON_THROW_ON_ERROR));
    $actor=null; $crewId=0;
    fc_product_admin_begin($pdo);
    try{
        $actor=fc_product_admin_actor($pdo);
        $challenge=fc_product_admin_lock_challenge_public($pdo,$challengePublicId); $crewId=(int)$challenge['crew_id'];
        if(!in_array($operation,fc_product_admin_challenge_allowed($actor,$challenge),true)) throw new DomainException('challenge_operation_denied');
        $prior=fc_product_admin_receipt($pdo,(int)$actor['id'],'CHALLENGE',$challengePublicId,$requestKey);
        if($prior!==null){
            if(!hash_equals((string)$prior['request_digest'],$digest)) throw new DomainException('idempotency_conflict');
            $result=json_decode((string)$prior['result_json'],true,512,JSON_THROW_ON_ERROR); $pdo->commit(); return $result+['replayed'=>true];
        }
        $state=fc_product_admin_challenge_state($pdo,$challenge);
        fc_product_admin_assert_revision($revision,$state['revision'],'stale_challenge_state');
        $before=$after=$extra=[]; $event='ADMIN_CHALLENGE_OPERATION';

        if($operation==='edit_name'){
            if(array_keys($fields)!==['display_name']) throw new InvalidArgumentException('invalid_challenge_fields');
            $name=fc_product_admin_text($fields['display_name'],140,'display_name');
            $before=['display_name'=>$challenge['display_name']]; $after=['display_name'=>$name];
            if($name!==(string)$challenge['display_name']){
                fc_product_admin_query($pdo,'UPDATE challenges SET display_name=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?',[$name,(int)$challenge['id']]);
                fc_family_event($pdo,(int)$challenge['id'],(int)$actor['id'],'CHALLENGE_RENAMED',null,['before'=>$challenge['display_name'],'after'=>$name,'source'=>'ADMIN_2C']);
            }
            $event='ADMIN_CHALLENGE_EDITED';
        } elseif($operation==='edit_rule_draft'){
            if((string)$challenge['lifecycle_status']==='COMPLETED') throw new DomainException('completed_challenge_rules_locked');
            $draft=$state['draft_rule']; $published=$state['published_rule'];
            if($draft===null){
                if($published===null) throw new DomainException('challenge_rule_draft_unavailable');
                $versions=fc_product_admin_query($pdo,'SELECT id,version_number FROM challenge_rule_versions WHERE challenge_id=? ORDER BY version_number FOR UPDATE',[(int)$challenge['id']])->fetchAll(PDO::FETCH_ASSOC);
                $version=$versions===[] ? 1 : ((int)$versions[count($versions)-1]['version_number']+1);
                $draftPublicId=fc_new_public_id();
                fc_product_admin_query($pdo,
                    "INSERT INTO challenge_rule_versions (public_id,challenge_id,version_number,version_status,supersedes_version_id,scoring_standard_code,planned_start_date,duration_days,challenge_timezone,weekly_checkin_day,live_leaderboard_visible,created_by_user_id)
                     VALUES (?,?,?,'DRAFT',?,?,?,?,?,?,?,?)",
                    [$draftPublicId,(int)$challenge['id'],$version,(int)$published['id'],(string)$published['scoring_standard_code'],$published['planned_start_date'],(int)$published['duration_days'],(string)$published['challenge_timezone'],(int)$published['weekly_checkin_day'],(int)$published['live_leaderboard_visible'],(int)$actor['id']]);
                $draft=fc_product_admin_query($pdo,'SELECT * FROM challenge_rule_versions WHERE public_id=? FOR UPDATE',[$draftPublicId])->fetch(PDO::FETCH_ASSOC);
                $extra['created_rule_draft']=true;
            }
            $values=fc_product_admin_rule_values($draft,$fields);
            $before=array_intersect_key($draft,$values); $after=$values;
            fc_product_admin_query($pdo,
                "UPDATE challenge_rule_versions SET planned_start_date=?,duration_days=?,challenge_timezone=?,weekly_checkin_day=?,live_leaderboard_visible=? WHERE id=? AND version_status='DRAFT'",
                [$values['planned_start_date'],$values['duration_days'],$values['challenge_timezone'],$values['weekly_checkin_day'],$values['live_leaderboard_visible'],(int)$draft['id']]);
            $extra += ['rule_draft_public_id'=>(string)$draft['public_id'],'rule_version_number'=>(int)$draft['version_number'],'published_rules_unchanged'=>true];
            $event='ADMIN_CHALLENGE_RULE_DRAFT_EDITED';
        } elseif($operation==='remove_participant'){
            if(array_keys($fields)!==['participant_public_id'] || !fc_public_id_is_valid((string)$fields['participant_public_id'])) throw new InvalidArgumentException('invalid_participant');
            $participant=fc_product_admin_query($pdo,'SELECT id,public_id,display_name FROM users WHERE public_id=? FOR UPDATE',[(string)$fields['participant_public_id']])->fetch(PDO::FETCH_ASSOC);
            if($participant===false) throw new DomainException('participant_unavailable');
            $participantId=(int)$participant['id'];
            $p=fc_product_admin_query($pdo,'SELECT * FROM challenge_participations WHERE challenge_id=? AND user_id=? FOR UPDATE',[(int)$challenge['id'],$participantId])->fetch(PDO::FETCH_ASSOC);
            if($p===false || (string)$p['participation_status']!=='ACTIVE') throw new DomainException('participant_not_active');
            fc_product_admin_query($pdo,"UPDATE challenge_participations SET participation_status='REMOVED',removed_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?",[(int)$p['id']]);
            fc_family_close_interval($pdo,(int)$challenge['id'],$participantId,'REMOVED');
            fc_family_event($pdo,(int)$challenge['id'],(int)$actor['id'],'PARTICIPANT_REMOVED',$participantId,['source'=>'ADMIN_2C']);
            fc_product_admin_query($pdo,'UPDATE user_product_contexts SET selected_challenge_id=NULL WHERE user_id=? AND selected_challenge_id=?',[$participantId,(int)$challenge['id']]);
            $before=['participant_public_id'=>(string)$participant['public_id'],'participation_status'=>'ACTIVE']; $after=['participant_public_id'=>(string)$participant['public_id'],'participation_status'=>'REMOVED'];
            $event='ADMIN_CHALLENGE_PARTICIPANT_REMOVED';
        } else {
            if($operation==='end'){
                if(array_diff(array_keys($fields),['end_reason'])!==[]) throw new InvalidArgumentException('unexpected_fields');
                $endReason=array_key_exists('end_reason',$fields)?fc_product_admin_text((string)$fields['end_reason'],500,'end_reason',true):'';
            } elseif($fields!==[]) throw new InvalidArgumentException('unexpected_fields');
            $controls=$state['owner_controls'];
            fc_product_admin_query($pdo,'INSERT IGNORE INTO challenge_owner_controls (challenge_id) VALUES (?)',[(int)$challenge['id']]);
            if($operation==='end'){
                if($controls['effective_end_at']!==null) throw new DomainException('challenge_already_ended');
                fc_product_admin_query($pdo,'UPDATE challenge_owner_controls SET effective_end_at=UTC_TIMESTAMP(6),ended_by_user_id=?,end_reason=?,revision=revision+1 WHERE challenge_id=?',[(int)$actor['id'],$endReason===''?null:$endReason,(int)$challenge['id']]);
                fc_crew_current_challenge_release($pdo,$crewId,(int)$challenge['id']);
                fc_family_event($pdo,(int)$challenge['id'],(int)$actor['id'],'CHALLENGE_ENDED',null,['reason'=>$endReason,'competitive_consequence'=>'NOT_DETERMINED','source'=>'ADMIN_2C']);
                $before=['effective_end_at'=>null]; $after=['effective_end_at'=>'SET']; $event='ADMIN_CHALLENGE_ENDED';
            } elseif($operation==='archive'){
                if($controls['archived_at']!==null) throw new DomainException('challenge_already_archived');
                fc_product_admin_query($pdo,'UPDATE challenge_owner_controls SET archived_at=UTC_TIMESTAMP(6),archived_by_user_id=?,revision=revision+1 WHERE challenge_id=?',[(int)$actor['id'],(int)$challenge['id']]);
                fc_crew_current_challenge_release($pdo,$crewId,(int)$challenge['id']);
                fc_family_event($pdo,(int)$challenge['id'],(int)$actor['id'],'CHALLENGE_ARCHIVED',null,['source'=>'ADMIN_2C']);
                $before=['archived_at'=>null]; $after=['archived_at'=>'SET']; $event='ADMIN_CHALLENGE_ARCHIVED';
            } else {
                if($controls['archived_at']===null) throw new DomainException('challenge_not_archived');
                if($controls['effective_end_at']!==null || $controls['deleted_at']!==null || (string)$challenge['lifecycle_status']==='COMPLETED') throw new DomainException('challenge_cannot_be_restored');
                fc_product_admin_query($pdo,'UPDATE challenge_owner_controls SET archived_at=NULL,archived_by_user_id=NULL,revision=revision+1 WHERE challenge_id=?',[(int)$challenge['id']]);
                fc_crew_current_challenge_restore($pdo,$crewId,(int)$challenge['id']);
                fc_family_event($pdo,(int)$challenge['id'],(int)$actor['id'],'CHALLENGE_UNARCHIVED',null,['source'=>'ADMIN_2C']);
                $before=['archived_at'=>'SET']; $after=['archived_at'=>null]; $event='ADMIN_CHALLENGE_UNARCHIVED';
            }
        }

        fc_product_admin_actor($pdo);
        $audit=fc_product_admin_audit($pdo,$actor,$event,'CHALLENGE',$challengePublicId,$crewId,$reason,$before,$after,$extra);
        $result=['operation'=>$operation,'target_public_id'=>$challengePublicId,'audit_id'=>$audit]+$extra;
        fc_product_admin_store_receipt($pdo,(int)$actor['id'],'CHALLENGE',$challengePublicId,$requestKey,$digest,$operationKey,$result);
        $pdo->commit(); return $result+['replayed'=>false];
    } catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        fc_product_admin_denied_audit($pdo,$actor,'CHALLENGE',$challengePublicId,$crewId,$operation,$reason,$e);
        throw $e;
    }
}

function fc_product_admin_crew_edit(PDO $pdo,string $target,array $fields,string $revision,string $requestKey,string $reason): array
{ return fc_product_admin_crew_mutate($pdo,$target,'edit',$fields,$revision,$requestKey,$reason); }
function fc_product_admin_crew_transfer_owner(PDO $pdo,string $target,string $newOwnerPublicId,string $revision,string $requestKey,string $reason): array
{ return fc_product_admin_crew_mutate($pdo,$target,'transfer_owner',['new_owner_public_id'=>$newOwnerPublicId],$revision,$requestKey,$reason); }
function fc_product_admin_crew_archive(PDO $pdo,string $target,string $revision,string $requestKey,string $reason): array
{ return fc_product_admin_crew_mutate($pdo,$target,'archive',[],$revision,$requestKey,$reason); }
function fc_product_admin_crew_restore(PDO $pdo,string $target,string $revision,string $requestKey,string $reason): array
{ return fc_product_admin_crew_mutate($pdo,$target,'restore',[],$revision,$requestKey,$reason); }
function fc_product_admin_crew_member_remove(PDO $pdo,string $target,string $memberPublicId,string $revision,string $requestKey,string $reason): array
{ return fc_product_admin_crew_mutate($pdo,$target,'remove_member',['member_public_id'=>$memberPublicId],$revision,$requestKey,$reason); }

function fc_product_admin_challenge_edit_name(PDO $pdo,string $target,string $displayName,string $revision,string $requestKey,string $reason): array
{ return fc_product_admin_challenge_mutate($pdo,$target,'edit_name',['display_name'=>$displayName],$revision,$requestKey,$reason); }
function fc_product_admin_challenge_rule_draft_edit(PDO $pdo,string $target,array $fields,string $revision,string $requestKey,string $reason): array
{ return fc_product_admin_challenge_mutate($pdo,$target,'edit_rule_draft',$fields,$revision,$requestKey,$reason); }
function fc_product_admin_challenge_participant_remove(PDO $pdo,string $target,string $participantPublicId,string $revision,string $requestKey,string $reason): array
{ return fc_product_admin_challenge_mutate($pdo,$target,'remove_participant',['participant_public_id'=>$participantPublicId],$revision,$requestKey,$reason); }
function fc_product_admin_challenge_end(PDO $pdo,string $target,?string $endReason,string $revision,string $requestKey,string $reason): array
{ return fc_product_admin_challenge_mutate($pdo,$target,'end',['end_reason'=>$endReason??''],$revision,$requestKey,$reason); }
function fc_product_admin_challenge_archive(PDO $pdo,string $target,string $revision,string $requestKey,string $reason): array
{ return fc_product_admin_challenge_mutate($pdo,$target,'archive',[],$revision,$requestKey,$reason); }
function fc_product_admin_challenge_unarchive(PDO $pdo,string $target,string $revision,string $requestKey,string $reason): array
{ return fc_product_admin_challenge_mutate($pdo,$target,'unarchive',[],$revision,$requestKey,$reason); }
