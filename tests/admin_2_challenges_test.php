<?php
declare(strict_types=1);
require __DIR__.'/admin_2_support.php';
$p=a2_db();$f=a2_fixture($p);$target='01ARZ3NDEKTSV4RRFFQ69G5FH1';
$p->exec("INSERT INTO crew_current_challenges(crew_id,challenge_id) VALUES(1,1)");
$p->exec("INSERT INTO challenge_rule_versions(id,public_id,challenge_id,version_number,version_status,challenge_timezone,created_by_user_id,published_by_user_id,published_at,planned_start_date) VALUES(1,'01ARZ3NDEKTSV4RRFFQ69G5FR1',1,1,'PUBLISHED','UTC',3,3,UTC_TIMESTAMP(6),'2026-10-01')");
$p->exec("INSERT INTO challenge_acceptance_records(challenge_id,user_id,rule_version_id,contract_code) VALUES(1,3,1,'BODY_COMPOSITION_V1_0')");
$p->exec("INSERT INTO challenge_participations(challenge_id,user_id) VALUES(1,3)");
$published=$p->query('SELECT * FROM challenge_rule_versions WHERE id=1')->fetch();$acceptance=$p->query('SELECT * FROM challenge_acceptance_records')->fetchAll();
a2_login($f,'admin');a2_denied(fn()=>a2_apply($p,'challenge',$target,'edit_name',['display_name'=>'Denied']),'challenge_operation_denied');
$p->exec("UPDATE challenges SET lifecycle_status='DRAFT' WHERE id=1");a2_apply($p,'challenge',$target,'edit_name',['display_name'=>'Admin draft name']);
a2_apply($p,'challenge',$target,'edit_rule_draft',['planned_start_date'=>'2026-10-05','duration_days'=>'90','challenge_timezone'=>'UTC','weekly_checkin_day'=>'1','live_leaderboard_visible'=>'0']);
a2_assert($p->query('SELECT * FROM challenge_rule_versions WHERE id=1')->fetch()===$published && $p->query('SELECT * FROM challenge_acceptance_records')->fetchAll()===$acceptance,'Rule correction preserves published rows and acceptance history');
a2_assert((int)$p->query("SELECT supersedes_version_id FROM challenge_rule_versions WHERE version_status='DRAFT'")->fetchColumn()===1,'Owner creates versioned correction draft');
a2_login($f,'super');$p->exec("UPDATE challenges SET lifecycle_status='LIVE' WHERE id=1");
$t=a2_ticket($p,'challenge',$target,'edit_name',['display_name'=>'Live correction']);$r=fc_admin_operation_execute($p,$t);$again=fc_admin_operation_execute($p,$t);
a2_assert($again['replayed'] && $again['audit_id']===$r['audit_id'],'Challenge receipt repeat-safe');
$stale=a2_ticket($p,'challenge',$target,'edit_name',['display_name'=>'Stale']);a2_apply($p,'challenge',$target,'edit_name',['display_name'=>'Current']);a2_denied(fn()=>fc_admin_operation_execute($p,$stale),'stale_challenge_state');
a2_apply($p,'challenge',$target,'remove_participant',['participant_public_id'=>$f['user']['public_id']]);
a2_assert($p->query('SELECT membership_status FROM crew_memberships WHERE user_id=3')->fetchColumn()==='ACTIVE' && $p->query('SELECT participation_status FROM challenge_participations WHERE user_id=3')->fetchColumn()==='REMOVED','Participant removal preserves Crew membership');
a2_apply($p,'challenge',$target,'archive');a2_apply($p,'challenge',$target,'unarchive');a2_apply($p,'challenge',$target,'end',['end_reason'=>'Operator reviewed end']);
a2_assert($p->query('SELECT lifecycle_status FROM challenges WHERE id=1')->fetchColumn()==='LIVE' && (int)$p->query('SELECT COUNT(*) FROM crew_current_challenges')->fetchColumn()===0,'End uses owner controls and leaves canonical lifecycle unchanged');
$p->exec("UPDATE challenges SET lifecycle_status='COMPLETED',completed_at=UTC_TIMESTAMP(6) WHERE id=1");
a2_denied(fn()=>a2_apply($p,'challenge',$target,'edit_rule_draft',['duration_days'=>'91']),'challenge_operation_denied');
a2_apply($p,'challenge',$target,'edit_name',['display_name'=>'Completed correction']);a2_apply($p,'challenge',$target,'archive');a2_denied(fn()=>a2_apply($p,'challenge',$target,'unarchive'),'challenge_operation_denied');
a2_assert(!in_array('unarchive',fc_admin_operation_snapshot($p,'challenge',$target)['allowed_operations'],true) && (int)$p->query('SELECT COUNT(*) FROM crew_current_challenges WHERE challenge_id=1')->fetchColumn()===0,'COMPLETED cannot advertise or regain current Challenge authority');
$h=a2_start_http($f);
try {
    foreach(['challenges.php','challenge.php?id='.$target] as $path) a2_assert(a2_http($h,'/admin/'.$path,$f['super']['raw'])['status']===200,'Challenge surface renders '.$path);
    $detail=a2_http($h,'/admin/challenge.php?id='.$target,$f['super']['raw']);a2_assert(!str_contains($detail['body'],'action=edit_rule_draft') && !str_contains($detail['body'],'action=unarchive') && str_contains($detail['body'],'COMPLETED'),'Completed Challenge omits competitive editor');
    $r=a2_review($h,$f,'super','challenge',$target,'edit_name',['display_name'=>'HTTP Challenge']);a2_assert(a2_confirm($h,$f,'super',$r)['status']===303,'Challenge name confirmation calls owner through HTTP');
} finally { a2_stop_http($h); }
echo "ADMIN-2C CONSOLE PROOF: PASS\n";
