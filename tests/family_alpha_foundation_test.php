<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/product/bootstrap.php';
function fa_assert(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
function fa_denied(callable $f,string $label):void { try {$f();}catch(DomainException|InvalidArgumentException){return;}throw new RuntimeException($label.': denial expected'); }
function fa_count(PDO $pdo,string $table,int $id):int { $q=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE challenge_id=:id');$q->execute([':id'=>$id]);return(int)$q->fetchColumn(); }
if (!in_array('mysql',PDO::getAvailableDrivers(),true)) { fwrite(STDERR,"[BLOCKED] PDO MySQL driver is required for the DB-backed Family Alpha proof.\n");exit(2); }
$pdo=fc_db();
$pdo->beginTransaction();
try {
    $owner=fc_user_create($pdo,'Family Alpha Owner');$member=fc_user_create($pdo,'Family Alpha Member');$other=fc_user_create($pdo,'Family Alpha Other');$outsider=fc_user_create($pdo,'Family Alpha Outsider');$platform=fc_user_create($pdo,'Family Alpha Platform','ACTIVE','PLATFORM_ADMIN');
    $crew=fc_crew_create($pdo,$owner['id'],'Family Alpha fixture');
    // Fixture setup only. No browser route may enroll by a Member ID.
    fc_crew_membership_add_existing($pdo,$owner['id'],$crew['id'],$member['id']);
    fc_crew_membership_add_existing($pdo,$owner['id'],$crew['id'],$other['id']);
    $c=fc_challenge_create($pdo,$owner['id'],$crew['id'],'Family Alpha test',['planned_start_date'=>'2100-01-01','challenge_timezone'=>'America/New_York']);
    $id=$c['id'];
    $eventCount=fa_count($pdo,'challenge_product_events',$id);
    try { fc_product_atomic($pdo,function() use($pdo,$id,$owner):void { fc_family_event($pdo,$id,$owner['id'],'CHALLENGE_RENAMED',null,['test'=>'rollback']);throw new RuntimeException('Controlled rollback probe'); }); } catch (RuntimeException $e) { if ($e->getMessage()!=='Controlled rollback probe') throw $e; }
    fa_assert($pdo->inTransaction() && fa_count($pdo,'challenge_product_events',$id)===$eventCount,'Nested failure must roll back only its operation and keep caller transaction.');
    $draft=fc_challenge_rule_current_draft($pdo,$id);
    fa_denied(fn()=>fc_challenge_join($pdo,$member['id'],$id),'Legacy no-consent join');
    fa_denied(fn()=>fc_challenge_accept_participation($pdo,$member['id'],$id,(int)$draft['id'],true,[]),'Draft cannot be accepted as a published contract');
    fc_challenge_offer_participation($pdo,$owner['id'],$id,$member['public_id']);
    $offer=fc_challenge_offer_for_user($pdo,$id,$member['id']);
    fa_assert($offer!==null && $offer['offer_status']==='PENDING','Owner invite must remain pending.');
    fa_assert(fc_challenge_participation_for_user($pdo,$id,$member['id'])===null,'Pending invitation must not create active participation.');
    fa_assert(fa_count($pdo,'challenge_acceptance_records',$id)===0,'Owner invitation must not accept for somebody else.');
    fa_denied(fn()=>fc_challenge_require_access($pdo,$member['id'],$id),'Pending user full Challenge access');
    fa_denied(fn()=>fc_challenge_participants($pdo,$member['id'],$id),'Pending roster enumeration');
    fa_denied(fn()=>fc_challenge_offer_participation($pdo,$member['id'],$id,$other['public_id']),'Non-owner invitation');
    fa_denied(fn()=>fc_challenge_offer_participation($pdo,$owner['id'],$id,$outsider['public_id']),'Non-Crew invitation via member selector');
    fc_challenge_rule_publish($pdo,$owner['id'],$id,(int)$draft['id']);
    $rule=fc_challenge_rule_current_published($pdo,$id);
    fa_denied(fn()=>fc_challenge_accept_participation($pdo,$member['id'],$id,(int)$rule['id'],false,[],(string)$offer['public_id']),'Unchecked consent');
    fa_denied(fn()=>fc_challenge_accept_participation($pdo,$member['id'],$id,999999,true,[],(string)$offer['public_id']),'Wrong Rule version');
    fa_denied(fn()=>fc_challenge_accept_participation($pdo,$member['id'],$id,(int)$rule['id'],true,[],'wrong-offer'),'Wrong pending offer');
    fc_challenge_accept_participation($pdo,$member['id'],$id,(int)$rule['id'],true,[],(string)$offer['public_id']);
    $p=fc_challenge_participation_for_user($pdo,$id,$member['id']);$originalJoined=$p['joined_at'];
    fa_assert($p['participation_status']==='ACTIVE','Personal acceptance must activate participation.');
    fa_assert(fa_count($pdo,'challenge_acceptance_records',$id)===1 && fa_count($pdo,'challenge_participation_intervals',$id)===1,'Exactly one consent receipt and interval expected.');
    fc_challenge_accept_participation($pdo,$member['id'],$id,(int)$rule['id'],true,[],(string)$offer['public_id']);
    fa_assert(fa_count($pdo,'challenge_acceptance_records',$id)===1 && fa_count($pdo,'challenge_participation_intervals',$id)===1,'Repeat accept must be idempotent.');
    $policy=fc_challenge_disclosure_policy($pdo,$owner['id'],$id,$member['id']);
    fa_assert($policy===['competition_results'=>true,'official_measurements'=>false,'personal_progress'=>false,'raw_provider_data'=>false],'Owner cannot bypass private defaults.');
    fa_denied(fn()=>fc_challenge_disclosure_policy($pdo,$other['id'],$id,$member['id']),'Crew membership is not Challenge audience');
    fa_denied(fn()=>fc_challenge_disclosure_policy($pdo,$platform['id'],$id,$member['id']),'Platform role privacy bypass');
    // A forged target-user value cannot change someone else's preferences.
    fc_challenge_privacy_save($pdo,$owner['id'],$id,['user_id'=>$member['id'],'measurements_visibility'=>'CHALLENGE']);
    fa_assert(fc_challenge_privacy_for_user($pdo,$id,$member['id'])['measurements_visibility']==='PRIVATE','Owner write must not change participant choices.');
    fc_challenge_privacy_save($pdo,$member['id'],$id,['measurements_visibility'=>'CHALLENGE','progress_visibility'=>'CHALLENGE']);
    $policy=fc_challenge_disclosure_policy($pdo,$owner['id'],$id,$member['id']);
    fa_assert($policy['official_measurements'] && $policy['personal_progress'] && !$policy['raw_provider_data'],'Participant opt-in must never share raw data.');
    fc_challenge_privacy_save($pdo,$member['id'],$id,[]);
    fa_assert(!fc_challenge_disclosure_policy($pdo,$owner['id'],$id,$member['id'])['official_measurements'],'Revocation must take effect on the next read.');
    $cards=fc_challenge_public_participant_cards($pdo,$owner['id'],$id);
    fa_assert(array_keys($cards[0])===['user_public_id','display_name','participation_status','entry_kind'],'Public payload must contain only the explicit safe fields.');
    // All later lifecycle states allow owner initiation; no downstream runtime is invoked.
    foreach (FC_CHALLENGE_LIFECYCLES as $stage) {
        $pdo->prepare('UPDATE challenges SET lifecycle_status=:s WHERE id=:id')->execute([':s'=>$stage,':id'=>$id]);
        fc_challenge_offer_participation($pdo,$owner['id'],$id,$other['public_id']);
        $o=fc_challenge_offer_for_user($pdo,$id,$other['id']);
        fa_assert($o['offer_status']==='PENDING','Invite must be available in '.$stage);
        fc_challenge_offer_decide($pdo,$owner['id'],$id,'cancel',(string)$o['public_id']);
    }
    fc_challenge_offer_participation($pdo,$owner['id'],$id,$other['public_id']);$oldOffer=fc_challenge_offer_for_user($pdo,$id,$other['id']);
    fc_challenge_offer_decide($pdo,$owner['id'],$id,'cancel',(string)$oldOffer['public_id']);
    fa_denied(fn()=>fc_challenge_accept_participation($pdo,$other['id'],$id,(int)$rule['id'],true,[],(string)$oldOffer['public_id']),'Cancelled offer replay');
    fc_challenge_offer_participation($pdo,$owner['id'],$id,$other['public_id']);$newOffer=fc_challenge_offer_for_user($pdo,$id,$other['id']);
    fa_assert($newOffer['public_id']!==$oldOffer['public_id'],'Renewed offer must have new acceptance authority.');
    fa_denied(fn()=>fc_challenge_accept_participation($pdo,$other['id'],$id,(int)$rule['id'],true,[],(string)$oldOffer['public_id']),'Re-invitation must invalidate old form');
    fc_challenge_offer_decide($pdo,$other['id'],$id,'decline',(string)$newOffer['public_id']);
    fa_assert(fc_challenge_participation_for_user($pdo,$id,$other['id'])===null,'Decline must not fabricate participation.');
    fa_denied(fn()=>fc_challenge_manage($pdo,$member['id'],$id,'end'),'Participant Owner-action bypass');
    fa_denied(fn()=>fc_challenge_manage($pdo,$platform['id'],$id,'delete'),'Platform Owner-action bypass');
    fc_challenge_manage($pdo,$owner['id'],$id,'rename',['display_name'=>'Renamed by Owner','expected_revision'=>0]);
    fa_denied(fn()=>fc_challenge_manage($pdo,$owner['id'],$id,'rename',['display_name'=>'Stale write','expected_revision'=>0]),'Stale Owner form');
    fc_challenge_manage($pdo,$owner['id'],$id,'archive');
    fa_assert(fc_challenges_for_user($pdo,$owner['id'])===[],'Archive must remove normal active listing.');
    fc_challenge_manage($pdo,$owner['id'],$id,'unarchive');
    fa_assert(count(fc_challenges_for_user($pdo,$owner['id']))===1,'Unarchive restores listing when no other hidden condition exists.');
    $priorLifecycle=fc_challenge_require_owner($pdo,$owner['id'],$id)['lifecycle_status'];
    fc_challenge_manage($pdo,$owner['id'],$id,'end',['reason'=>'Alpha proof']);
    $ended=fc_challenge_management_state($pdo,$id);
    fa_assert($ended['effective_end_at']!==null && (int)$ended['ended_by_user_id']===$owner['id'],'End needs timestamp and Owner actor.');
    fc_challenge_manage($pdo,$owner['id'],$id,'end',['reason'=>'Repeat must not replace reason']);
    fa_assert(fc_challenge_management_state($pdo,$id)['end_reason']==='Alpha proof','Repeated end must preserve first effective end.');
    fa_assert(fc_challenge_require_owner($pdo,$owner['id'],$id)['lifecycle_status']===$priorLifecycle,'Ending must not invent lifecycle/result finalization.');
    fc_challenge_manage($pdo,$owner['id'],$id,'delete');
    fa_assert(fc_challenge_management_state($pdo,$id)['deleted_at']!==null,'History-bearing Challenge delete stays available.');
    fa_assert(fa_count($pdo,'challenge_rule_versions',$id)===1 && fa_count($pdo,'challenge_acceptance_records',$id)===1,'Delete must preserve rules and acceptance.');
    fa_assert(count(fc_challenges_for_user($pdo,$owner['id'],null,true))===1,'Owner history lookup remains available.');
    fc_challenge_withdraw($pdo,$member['id'],$id);
    $withdrawn=fc_challenge_participation_for_user($pdo,$id,$member['id']);
    fa_assert($withdrawn['participation_status']==='WITHDRAWN' && $withdrawn['withdrawn_at']!==null,'Withdraw after end/delete must work.');
    fc_challenge_withdraw($pdo,$member['id'],$id);
    fa_assert(fc_challenge_participation_for_user($pdo,$id,$member['id'])['withdrawn_at']===$withdrawn['withdrawn_at'],'Duplicate withdraw must not rewrite timestamp.');
    fa_assert(fc_challenge_participants($pdo,$member['id'],$id)===[],'Former participant must not receive current roster.');
    fc_challenge_personal_context($pdo,$member['id'],$id);
    fc_challenge_privacy_save($pdo,$member['id'],$id,[]);
    fc_challenge_offer_participation($pdo,$owner['id'],$id,$member['public_id']);$rejoin=fc_challenge_offer_for_user($pdo,$id,$member['id']);
    fc_challenge_accept_participation($pdo,$member['id'],$id,(int)$rule['id'],true,[],(string)$rejoin['public_id']);
    fa_assert(fa_count($pdo,'challenge_participation_intervals',$id)===2,'Rejoin must add an interval instead of erasing the first.');
    fa_assert(fc_challenge_participation_for_user($pdo,$id,$member['id'])['joined_at']===$originalJoined,'Original joined_at must survive rejoin.');
    $interval=$pdo->prepare('SELECT exited_at,exit_status FROM challenge_participation_intervals WHERE challenge_id=:c AND user_id=:u ORDER BY id LIMIT 1');$interval->execute([':c'=>$id,':u'=>$member['id']]);$first=$interval->fetch(PDO::FETCH_ASSOC);
    fa_assert($first['exit_status']==='WITHDRAWN' && $first['exited_at']===$withdrawn['withdrawn_at'],'Historical withdrawal must survive rejoin.');
    $draft2=fc_challenge_rule_begin_update($pdo,$owner['id'],$id);fc_challenge_rule_publish($pdo,$owner['id'],$id,$draft2['id']);
    fa_assert(fa_count($pdo,'challenge_acceptance_records',$id)===2,'Owner Rule change must not accept on behalf of participants.');
    fa_denied(fn()=>fc_challenge_accept_participation($pdo,$member['id'],$id,(int)$rule['id'],true,[]),'Stale Rule acceptance after update');
    fc_crew_membership_remove($pdo,$owner['id'],$crew['id'],$member['id']);
    fa_denied(fn()=>fc_challenge_require_access($pdo,$member['id'],$id),'Crew removal revokes ordinary Challenge access');
    $personal=fc_challenge_personal_context($pdo,$member['id'],$id);
    fa_assert($personal['participation']['participation_status']==='REMOVED','Removed participant retains own history context.');
    fc_challenge_privacy_save($pdo,$member['id'],$id,[]);
    fa_assert(count(fc_challenge_personal_list($pdo,$member['id']))===1,'Personal record remains discoverable without Crew membership.');
    $pristine=fc_challenge_create($pdo,$owner['id'],$crew['id'],'Disposable pristine Draft');
    fc_challenge_delete_draft($pdo,$owner['id'],$pristine['id']);
    $q=$pdo->prepare('SELECT COUNT(*) FROM challenges WHERE id=:id');$q->execute([':id'=>$pristine['id']]);fa_assert((int)$q->fetchColumn()===0,'Pristine physical deletion must remain safe.');
    fa_denied(fn()=>fc_challenge_delete_draft($pdo,$owner['id'],$id),'History-bearing physical deletion');
    $pdo->rollBack();
    fwrite(STDOUT,"Family Alpha B-D database foundation proof: PASS\n- Owner invite stays Pending / no consent or access grant: PASS\n- Personal acceptance / stale Rules and offer replay protection / idempotence: PASS\n- Late-lifecycle owner invitations / personal decline: PASS\n- Owner end/archive/delete separated from scoring and history: PASS\n- Withdrawal/rejoin intervals / original joined_at / receipt preservation: PASS\n- Self-only category privacy / Owner and Crew-member negative access: PASS\n- Own-history access after Crew removal / safe participant read fields: PASS\n- Pristine hard-delete and history-bearing protection: PASS\n- Test data rolled back: PASS\n");
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,'[FAIL] '.$error->getMessage().PHP_EOL);exit(1);
}
