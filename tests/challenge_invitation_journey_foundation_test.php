<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/product/bootstrap.php';
function cij_assert(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
function cij_denied(callable $fn,string $label):void { try{$fn();}catch(DomainException|InvalidArgumentException|LogicException|PDOException){return;}throw new RuntimeException($label.': denial expected'); }
function cij_published_challenge(PDO $pdo,int $ownerId,int $crewId,string $name):array {
    $c=fc_challenge_create($pdo,$ownerId,$crewId,$name,['planned_start_date'=>'2100-01-01','challenge_timezone'=>'America/New_York']);
    $draft=fc_challenge_rule_current_draft($pdo,(int)$c['id']);
    fc_challenge_rule_publish($pdo,$ownerId,(int)$c['id'],(int)$draft['id']);
    return ['challenge'=>$c,'rule'=>fc_challenge_rule_current_published($pdo,(int)$c['id'])];
}
function cij_invite_intent(PDO $pdo,int $ownerId,array $crew,array $challenge,string $email,array $privacy=[]):array {
    $invite=fc_crew_invitation_create($pdo,$ownerId,(int)$crew['id'],(int)$challenge['id'],$email);
    $review=fc_challenge_invitation_review($pdo,(string)$invite['public_id'],(int)$invite['generation']);
    cij_assert($review!==null,'Challenge-scoped review unavailable.');
    $intent=fc_challenge_invitation_intent_create($pdo,$review,$privacy);
    fc_challenge_invitation_intent_session_set((string)$intent['public_id']);
    return ['invite'=>$invite,'review'=>$review,'intent'=>$intent];
}
if (!in_array('mysql',PDO::getAvailableDrivers(),true)) { fwrite(STDERR,"[BLOCKED] PDO MySQL driver is required for the Challenge invitation journey proof.\n");exit(2); }
$pdo=fc_db(); $sessionBefore=$_SESSION; $pdo->beginTransaction();
try {
    $owner=fc_user_create($pdo,'Journey Owner');
    $member=fc_user_create($pdo,'Journey Member');
    $newUser=fc_user_create($pdo,'Journey New');
    $rollbackUser=fc_user_create($pdo,'Journey Rollback');
    $crew=fc_crew_create($pdo,(int)$owner['id'],'Journey Crew');
    fc_crew_membership_add_existing($pdo,(int)$owner['id'],(int)$crew['id'],(int)$member['id']);
    $published=cij_published_challenge($pdo,(int)$owner['id'],(int)$crew['id'],'Journey Current');
    $challenge=$published['challenge']; $rule=$published['rule'];

    $current=fc_crew_current_challenge($pdo,(int)$crew['id']);
    cij_assert($current!==null && (int)$current['id']===(int)$challenge['id'],'Challenge creation must establish database-backed current authority.');
    cij_denied(fn()=>fc_challenge_create($pdo,(int)$owner['id'],(int)$crew['id'],'Illegal concurrent Challenge',['planned_start_date'=>'2100-02-01']),'Second current Challenge');
    cij_assert((int)$pdo->query('SELECT COUNT(*) FROM crew_current_challenges WHERE crew_id='.(int)$crew['id'])->fetchColumn()===1,'Crew current-Challenge pointer must be unique.');

    $membershipBefore=(int)$pdo->query('SELECT COUNT(*) FROM crew_memberships')->fetchColumn();
    $participationBefore=(int)$pdo->query('SELECT COUNT(*) FROM challenge_participations')->fetchColumn();
    $flow=cij_invite_intent($pdo,(int)$owner['id'],$crew,$challenge,'new-person@example.test',['measurements_visibility'=>'PRIVATE','progress_visibility'=>'CHALLENGE']);
    cij_assert((int)$flow['review']['challenge_id']===(int)$challenge['id'] && (int)$flow['review']['crew_id']===(int)$crew['id'],'Invitation must bind exact Crew + current Challenge.');
    cij_assert((int)$pdo->query('SELECT COUNT(*) FROM crew_memberships')->fetchColumn()===$membershipBefore && (int)$pdo->query('SELECT COUNT(*) FROM challenge_participations')->fetchColumn()===$participationBefore,'Opening/reviewing invitation must create no membership or participation.');
    cij_assert((int)$flow['review']['rule_version_id']===(int)$rule['id'],'Review must bind current published Rules.');

    // Server-side intent survives loss of ordinary Website session state and can be resumed by invitation/generation.
    $intentPublic=(string)$flow['intent']['public_id'];
    fc_challenge_invitation_intent_session_clear();
    cij_assert(fc_challenge_invitation_intent_resume_for_invitation($pdo,(string)$flow['invite']['public_id'],0)===$intentPublic,'Acceptance intent must resume after Auth-owned account/session switch.');

    // Already-signed-in / authenticated path: one Website transaction creates Crew + Challenge state atomically.
    $result=fc_challenge_invitation_enroll($pdo,(int)$newUser['id'],false);
    cij_assert((int)$result['crew_id']===(int)$crew['id'] && (int)$result['challenge_id']===(int)$challenge['id'],'Enrollment returned wrong Crew/Challenge.');
    $m=$pdo->prepare('SELECT role_code,membership_status FROM crew_memberships WHERE crew_id=? AND user_id=?');$m->execute([$crew['id'],$newUser['id']]);$mr=$m->fetch(PDO::FETCH_ASSOC);
    cij_assert($mr && $mr['role_code']==='MEMBER' && $mr['membership_status']==='ACTIVE','Enrollment must create active Crew membership.');
    $p=$pdo->prepare('SELECT participation_status FROM challenge_participations WHERE challenge_id=? AND user_id=?');$p->execute([$challenge['id'],$newUser['id']]);
    cij_assert($p->fetchColumn()==='ACTIVE','Enrollment must create active Challenge participation.');
    $a=$pdo->prepare('SELECT rule_version_id,contract_code,measurements_visibility,progress_visibility FROM challenge_acceptance_records WHERE challenge_id=? AND user_id=? ORDER BY id DESC LIMIT 1');$a->execute([$challenge['id'],$newUser['id']]);$ar=$a->fetch(PDO::FETCH_ASSOC);
    cij_assert($ar && (int)$ar['rule_version_id']===(int)$rule['id'] && $ar['contract_code']===FC_FAMILY_ALPHA_CONTRACT && $ar['measurements_visibility']==='PRIVATE' && $ar['progress_visibility']==='CHALLENGE','Exact Rules/consent/privacy acceptance not recorded.');
    $ctx=fc_product_context($pdo,(int)$newUser['id']);
    cij_assert((int)($ctx['crew']['id']??0)===(int)$crew['id'] && (int)($ctx['challenge']['id']??0)===(int)$challenge['id'],'Successful enrollment must select Crew + Challenge context.');

    // Existing Crew member receives participation without duplicate membership.
    $memberCount=$pdo->prepare('SELECT COUNT(*) FROM crew_memberships WHERE crew_id=? AND user_id=?');$memberCount->execute([$crew['id'],$member['id']]);$before=(int)$memberCount->fetchColumn();
    $flow2=cij_invite_intent($pdo,(int)$owner['id'],$crew,$challenge,'existing-member@example.test');
    fc_challenge_invitation_enroll($pdo,(int)$member['id'],false);
    $memberCount->execute([$crew['id'],$member['id']]);
    cij_assert((int)$memberCount->fetchColumn()===$before,'Existing Crew member must not be duplicated.');
    cij_assert(fc_challenge_participation_for_user($pdo,(int)$challenge['id'],(int)$member['id'])['participation_status']==='ACTIVE','Existing Crew member must gain Challenge participation.');

    // OWNER remains OWNER and can become a participant idempotently.
    $flow3=cij_invite_intent($pdo,(int)$owner['id'],$crew,$challenge,'owner-alt@example.test');
    fc_challenge_invitation_enroll($pdo,(int)$owner['id'],false);
    $role=$pdo->prepare('SELECT role_code FROM crew_memberships WHERE crew_id=? AND user_id=?');$role->execute([$crew['id'],$owner['id']]);
    cij_assert($role->fetchColumn()==='OWNER','Invitation acceptance must never downgrade Crew OWNER.');
    $parts=$pdo->prepare('SELECT COUNT(*) FROM challenge_participations WHERE challenge_id=? AND user_id=?');$parts->execute([$challenge['id'],$owner['id']]);$partCount=(int)$parts->fetchColumn();
    $flow4=cij_invite_intent($pdo,(int)$owner['id'],$crew,$challenge,'owner-second@example.test');
    $repeat=fc_challenge_invitation_enroll($pdo,(int)$owner['id'],false);
    $parts->execute([$challenge['id'],$owner['id']]);
    cij_assert((int)$parts->fetchColumn()===$partCount && $repeat['already_enrolled']===true,'Already-participant enrollment must be idempotent.');

    // Consume failure must roll back Website membership/participation/invitation when caller rolls back the transaction.
    $flow5=cij_invite_intent($pdo,(int)$owner['id'],$crew,$challenge,'rollback@example.test');
    $pdo->exec('SAVEPOINT journey_consume_failure');
    try { fc_challenge_invitation_enroll($pdo,(int)$rollbackUser['id'],true); throw new RuntimeException('Missing Auth continuation unexpectedly consumed.'); }
    catch (DomainException $expected) { $pdo->exec('ROLLBACK TO SAVEPOINT journey_consume_failure'); $pdo->exec('RELEASE SAVEPOINT journey_consume_failure'); }
    $m->execute([$crew['id'],$rollbackUser['id']]); cij_assert($m->fetch(PDO::FETCH_ASSOC)===false,'Auth consume failure must not leave Crew membership.');
    cij_assert(fc_challenge_participation_for_user($pdo,(int)$challenge['id'],(int)$rollbackUser['id'])===null,'Auth consume failure must not leave Challenge participation.');
    $is=$pdo->prepare('SELECT invitation_status FROM crew_invitations WHERE public_id=?');$is->execute([$flow5['invite']['public_id']]);cij_assert($is->fetchColumn()==='PENDING','Auth consume failure must preserve pending invitation after rollback.');

    // Rules change requires re-review; stale intent cannot silently accept new terms.
    $stale=cij_invite_intent($pdo,(int)$owner['id'],$crew,$challenge,'stale-rules@example.test');
    $draft2=fc_challenge_rule_begin_update($pdo,(int)$owner['id'],(int)$challenge['id']);
    fc_challenge_rule_publish($pdo,(int)$owner['id'],(int)$challenge['id'],(int)$draft2['id']);
    cij_denied(fn()=>fc_challenge_invitation_enroll($pdo,(int)$rollbackUser['id'],false),'Changed Rules stale intent');

    // Restore a current intent using new Rules for stale-generation/cancel/consent tests.
    fc_challenge_invitation_intent_session_clear();
    $rot=cij_invite_intent($pdo,(int)$owner['id'],$crew,$challenge,'rotated@example.test');
    fc_crew_invitation_resend($pdo,(int)$owner['id'],(int)$crew['id'],(string)$rot['invite']['public_id']);
    cij_denied(fn()=>fc_challenge_invitation_enroll($pdo,(int)$rollbackUser['id'],false),'Resend must invalidate stale acceptance intent');

    $cancel=cij_invite_intent($pdo,(int)$owner['id'],$crew,$challenge,'cancelled@example.test');
    fc_crew_invitation_cancel($pdo,(int)$owner['id'],(int)$crew['id'],(string)$cancel['invite']['public_id']);
    cij_denied(fn()=>fc_challenge_invitation_enroll($pdo,(int)$rollbackUser['id'],false),'Cancelled invitation must reject');

    $consent=cij_invite_intent($pdo,(int)$owner['id'],$crew,$challenge,'old-consent@example.test');
    $pdo->prepare("UPDATE challenge_invitation_acceptance_intents SET consent_version='OLD_CONTRACT' WHERE public_id=?")->execute([$consent['intent']['public_id']]);
    cij_denied(fn()=>fc_challenge_invitation_enroll($pdo,(int)$rollbackUser['id'],false),'Changed consent contract must require re-review');

    // Legacy Crew-only pending invitation is preserved but cannot masquerade as Challenge consent.
    $legacyPublic=fc_new_public_id(); $legacyToken=fc_crew_invitation_token();
    $pdo->prepare("INSERT INTO crew_invitations(public_id,crew_id,challenge_id,invited_email,invited_by_user_id,token_hash,invitation_status,expires_at,transport_status,sent_at) VALUES(?,?,NULL,?,?,?,'PENDING',DATE_ADD(CURRENT_TIMESTAMP(6),INTERVAL 1 DAY),'PENDING_SEND',NULL)")
        ->execute([$legacyPublic,$crew['id'],'legacy@example.test',$owner['id'],fc_crew_invitation_token_hash($legacyToken)]);
    cij_assert(fc_challenge_invitation_review($pdo,$legacyPublic,0)===null,'Legacy Crew-only invitation must not become Challenge review/consent.');

    // End current authority, prove historical row survives, then create exactly one next current Challenge.
    fc_challenge_manage($pdo,(int)$owner['id'],(int)$challenge['id'],'end',['reason'=>'Journey proof']);
    cij_assert(fc_crew_current_challenge($pdo,(int)$crew['id'])===null,'Terminal Owner end must release current-Challenge authority.');
    $oldExists=$pdo->prepare('SELECT COUNT(*) FROM challenges WHERE id=?');$oldExists->execute([$challenge['id']]);cij_assert((int)$oldExists->fetchColumn()===1,'Historical Challenge must survive current-authority release.');
    $next=fc_challenge_create($pdo,(int)$owner['id'],(int)$crew['id'],'Journey Next',['planned_start_date'=>'2101-01-01']);
    cij_assert((int)fc_crew_current_challenge($pdo,(int)$crew['id'])['id']===(int)$next['id'],'Next Challenge must become the sole current authority.');
    cij_denied(fn()=>fc_crew_invitation_create($pdo,(int)$owner['id'],(int)$crew['id'],(int)$challenge['id'],'historical@example.test'),'Owner cannot invite against historical/non-current Challenge');
    cij_assert((int)$pdo->query('SELECT COUNT(*) FROM challenges WHERE crew_id='.(int)$crew['id'])->fetchColumn()>=2,'Historical one-to-many Challenge relationship must be preserved.');

    $pdo->rollBack(); $_SESSION=$sessionBefore;
    fwrite(STDOUT,"Challenge invitation journey DB proof: PASS\n- one-current-Challenge authority / historical preservation: PASS\n- Challenge-scoped pre-auth review / no mutation on open: PASS\n- server-side acceptance intent / account-switch resume: PASS\n- atomic Crew + Challenge enrollment / consent / privacy / context: PASS\n- existing member / OWNER / participant idempotence: PASS\n- stale Rules / resend / cancel / consent rejection: PASS\n- Auth-consume rollback: PASS\n- legacy Crew-only invitation isolation: PASS\n- test data rolled back: PASS\n");
} catch(Throwable $e) {
    if($pdo->inTransaction()) $pdo->rollBack(); $_SESSION=$sessionBefore;
    fwrite(STDERR,'[FAIL] '.$e->getMessage().PHP_EOL); exit(1);
}
