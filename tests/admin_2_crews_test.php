<?php
declare(strict_types=1);
require __DIR__.'/admin_2_support.php';
$p=a2_db();$f=a2_fixture($p);$target='01ARZ3NDEKTSV4RRFFQ69G5FC1';
$p->exec("INSERT INTO crew_memberships(crew_id,user_id,role_code) VALUES(1,2,'MEMBER')");
$p->exec("INSERT INTO crew_current_challenges(crew_id,challenge_id) VALUES(1,1)");
$p->exec("INSERT INTO challenges(id,public_id,crew_id,owner_user_id,display_name,lifecycle_status) VALUES(2,'01ARZ3NDEKTSV4RRFFQ69G5FH2',1,3,'Historic','COMPLETED')");
a2_login($f,'admin');
$r=a2_apply($p,'crew',$target,'edit',['display_name'=>'Edited Crew','description'=>'Safe description']);
a2_assert($p->query('SELECT display_name FROM crews WHERE id=1')->fetchColumn()==='Edited Crew','Crew profile calls Product service');
foreach(['transfer_owner','archive','restore','remove_member'] as $action) a2_denied(fn()=>a2_apply($p,'crew',$target,$action,match($action){'transfer_owner'=>['new_owner_public_id'=>$f['admin']['public_id']],'remove_member'=>['member_public_id'=>$f['admin']['public_id']],default=>[]}),'crew_operation_denied');
a2_login($f,'super');
a2_denied(fn()=>a2_apply($p,'crew',$target,'archive'),'crew_has_current_challenge');
a2_denied(fn()=>a2_apply($p,'crew',$target,'remove_member',['member_public_id'=>$f['user']['public_id']]),'crew_owner_cannot_be_removed');
a2_denied(fn()=>a2_apply($p,'crew',$target,'transfer_owner',['new_owner_public_id'=>$f['super']['public_id']]),'new_owner_must_be_active_crew_member');
$t=a2_ticket($p,'crew',$target,'transfer_owner',['new_owner_public_id'=>$f['admin']['public_id']]);
$r=fc_admin_operation_execute($p,$t);$replay=fc_admin_operation_execute($p,$t);
a2_assert($replay['replayed'] && $r['audit_id']===$replay['audit_id'],'Crew ownership replay returns original audit receipt');
a2_assert((int)$p->query('SELECT owner_user_id FROM crews WHERE id=1')->fetchColumn()===2 && (int)$p->query('SELECT owner_user_id FROM challenges WHERE id=1')->fetchColumn()===2 && (int)$p->query('SELECT owner_user_id FROM challenges WHERE id=2')->fetchColumn()===3,'Transfer follows current Challenge but preserves historical owner');
a2_assert($p->query('SELECT role_code FROM crew_memberships WHERE crew_id=1 AND user_id=3')->fetchColumn()==='MEMBER','Previous owner remains member');
$p->exec("INSERT INTO challenge_participations(challenge_id,user_id,entry_kind) VALUES(1,3,'STANDARD')");
$p->exec("INSERT INTO challenge_participation_intervals(challenge_id,user_id,entered_at,entry_source) VALUES(1,3,UTC_TIMESTAMP(6),'LEGACY_SNAPSHOT')");
$r=a2_apply($p,'crew',$target,'remove_member',['member_public_id'=>$f['user']['public_id']]);
a2_assert($r['removed_active_challenge_participations']===1 && $p->query('SELECT participation_status FROM challenge_participations WHERE user_id=3')->fetchColumn()==='REMOVED','Explicit Product atomic removal updates membership and active participation');
a2_assert($p->query('SELECT exited_at FROM challenge_participation_intervals WHERE user_id=3')->fetchColumn()!==null,'Removal closes participation interval');
$stale=a2_ticket($p,'crew',$target,'edit',['display_name'=>'Stale']);a2_apply($p,'crew',$target,'edit',['display_name'=>'Fresh']);a2_denied(fn()=>fc_admin_operation_execute($p,$stale),'stale_crew_state');
$p->exec('DELETE FROM crew_current_challenges WHERE crew_id=1');a2_apply($p,'crew',$target,'archive');a2_apply($p,'crew',$target,'restore');
a2_assert($p->query('SELECT crew_status FROM crews WHERE id=1')->fetchColumn()==='ACTIVE','Archive/restore preserves Crew history');
$h=a2_start_http($f);
try {
    $r=a2_review($h,$f,'super','crew',$target,'edit',['display_name'=>'HTTP Crew','description'=>'Reviewed']);
    a2_assert($r['status']===200 && a2_confirm($h,$f,'super',$r)['status']===303,'Crew editor reviews and applies through HTTP');
    a2_assert(a2_http($h,'/admin/operation.php?kind=crew&target='.$target.'&action=archive',$f['admin']['raw'])['status']===403,'Ordinary Admin crafted higher-impact operation denied');
} finally { a2_stop_http($h); }
// Gate the accepted owner replay contract; never compensate with an Admin write/cache.
$issues=[];
a2_login($f,'super');
foreach(['archive','restore'] as $action) {
    $ticket=a2_ticket($p,'crew',$target,$action);$first=fc_admin_operation_execute($p,$ticket);
    try { $second=fc_admin_operation_execute($p,$ticket); if(!($second['replayed']??false) || $second['audit_id']!==$first['audit_id']) $issues[]=$action.': original receipt missing'; }
    catch(DomainException $e) { $issues[]=$action.': '.$e->getMessage(); }
}
if($issues!==[]) throw new RuntimeException('PRODUCT OWNER DEPENDENCY — exact Crew state-transition replay must return original receipt: '.implode('; ',$issues));
echo "ADMIN-2B CONSOLE PROOF: PASS\n";
