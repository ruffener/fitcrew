<?php
declare(strict_types=1);
require __DIR__ . '/admin_test_support.php';
$p=admin_test_db();$f=admin_test_fixture($p);
$role=fn(int $id)=>$p->query('SELECT platform_role_code FROM users WHERE id='.$id)->fetchColumn();
fc_admin_change_role($p,$f['super'],$f['user']['public_id'],'make','USER',true);
admin_test_assert($role(3)==='PLATFORM_ADMIN','Make Admin commits');
admin_test_assert((int)$p->query("SELECT COUNT(*) FROM audit_events WHERE event_type='ADMIN_ROLE_GRANTED' AND outcome='SUCCESS' AND actor_user_id=1 AND target_id='3' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.old_role'))='USER' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.new_role'))='PLATFORM_ADMIN'")->fetchColumn()===1,'Role audit records actor, target, old/new roles, timestamp and success');
admin_test_denied(fn()=>fc_admin_change_role($p,$f['super'],$f['user']['public_id'],'make','USER',true),'role_changed');
fc_admin_change_role($p,$f['super'],$f['user']['public_id'],'remove','PLATFORM_ADMIN',true);
admin_test_assert($role(3)==='USER','Remove Admin commits');
admin_test_denied(fn()=>fc_admin_change_role($p,$f['admin'],$f['user']['public_id'],'make','USER',true),'role_required');
admin_test_denied(fn()=>fc_admin_change_role($p,$f['super'],$f['super']['public_id'],'remove','PLATFORM_SUPER_ADMIN',true),'super_admin_protected');
admin_test_denied(fn()=>fc_admin_change_role($p,$f['super'],$f['user']['public_id'],'make','USER',false),'confirmation_required');
admin_test_denied(fn()=>fc_admin_change_role($p,$f['super'],$f['inactive']['public_id'],'make','USER',true),'target_inactive');
admin_test_denied(fn()=>fc_admin_change_role($p,$f['super'],$f['user']['public_id'],'super','USER',true),'invalid_action');
foreach (["UPDATE users SET platform_role_code='PLATFORM_SUPER_ADMIN' WHERE id=3", "UPDATE users SET platform_role_code='platform_super_admin' WHERE id=3", "UPDATE users SET platform_role_code='OWNER' WHERE id=3"] as $sql) {
    try { $p->exec($sql); throw new RuntimeException('Invalid role update accepted'); }
    catch (PDOException $e) { admin_test_assert(true,'Database rejects duplicate Super Admin or invalid role'); }
}
$p->exec("UPDATE users SET account_status='SUSPENDED' WHERE id=1");
try { $p->exec("UPDATE users SET platform_role_code='PLATFORM_SUPER_ADMIN' WHERE id=3"); throw new RuntimeException('Inactive singleton ignored'); }
catch (PDOException $e) { admin_test_assert(true,'Singleton applies even when existing Super Admin is inactive'); }
$p->exec("UPDATE users SET account_status='ACTIVE' WHERE id=1");
// Runtime authority must be re-read inside every role mutation.
$p->exec('UPDATE user_sessions SET revoked_at=CURRENT_TIMESTAMP(6) WHERE id=1');
admin_test_denied(fn()=>fc_admin_change_role($p,$f['super'],$f['user']['public_id'],'make','USER',true),'session_inactive');
$p->exec('UPDATE user_sessions SET revoked_at=NULL WHERE id=1');
$p->exec("UPDATE user_auth_identities SET identity_status='DISABLED' WHERE id=1");
admin_test_denied(fn()=>fc_admin_change_role($p,$f['super'],$f['user']['public_id'],'make','USER',true),'identity_inactive');
$p->exec("UPDATE user_auth_identities SET identity_status='ACTIVE' WHERE id=1");
// Prove success-audit failure rolls the role back, using a test-only database trigger.
$p->exec("CREATE TRIGGER admin_test_fail_audit BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test audit failure'");
try {
    try { fc_admin_change_role($p,$f['super'],$f['user']['public_id'],'make','USER',true); throw new RuntimeException('Audit failure accepted'); }
    catch (PDOException $e) { admin_test_assert($role(3)==='USER' && !$p->inTransaction(),'Audit failure rolls back mutation'); }
} finally { $p->exec('DROP TRIGGER admin_test_fail_audit'); }
$_SESSION=[];$target=fc_admin_user($p,$f['user']['public_id']);
$nonce=fc_admin_confirmation_issue($f['super'],$target,'make');
admin_test_assert(!fc_admin_confirmation_consume($f['super'],$nonce,$f['admin']['public_id'],'make','USER'),'Confirmation bound to target');
$nonce=fc_admin_confirmation_issue($f['super'],$target,'make');
admin_test_assert(fc_admin_confirmation_consume($f['super'],$nonce,$target['public_id'],'make','USER'),'Bound confirmation accepted');
admin_test_assert(!fc_admin_confirmation_consume($f['super'],$nonce,$target['public_id'],'make','USER'),'Confirmation replay rejected');
$nonce=fc_admin_confirmation_issue($f['super'],$target,'make');$_SESSION['fitcrew_admin_confirmations'][$nonce]['expires']=time()-1;
admin_test_assert(!fc_admin_confirmation_consume($f['super'],$nonce,$target['public_id'],'make','USER'),'Expired confirmation rejected');
$inv=fc_admin_invitation($p,'01ARZ3NDEKTSV4RRFFQ69G5FJ1');
admin_test_assert($inv['effective_status']==='EXPIRED' && $inv['invitation_status']==='PENDING','Expiry display does not mutate stored invitation');
admin_test_assert(!array_key_exists('token_hash',$inv),'Invitation token evidence excluded');
admin_test_assert($inv['invitation_scope']==='Challenge' && $inv['challenge_name']==='Alpha Challenge'
    && $inv['challenge_lifecycle']==='LAUNCHED','Invitation shows canonical Challenge scope and current lifecycle');
$legacy=fc_admin_invitation($p,'01ARZ3NDEKTSV4RRFFQ69G5FJ2');
admin_test_assert($legacy['invitation_scope']==='Legacy Crew-only' && $legacy['challenge_public_id']===null,
    'Historical Crew-only invitation remains distinct from Challenge consent');
admin_test_assert(count(fc_admin_list($p,'invitations','Alpha Challenge',1))===1,
    'Invitation search finds the associated Challenge without including legacy invitations');
$crewDetail=fc_admin_crew_details($p,fc_admin_crew($p,'01ARZ3NDEKTSV4RRFFQ69G5FC1'));
admin_test_assert(count($crewDetail['invitations'])===2 && $crewDetail['challenges'][0]['lifecycle_status']==='LAUNCHED',
    'Crew detail preserves both invitation scopes and the current Challenge lifecycle');
$detail=fc_admin_user_details($p,$target);
admin_test_assert(!str_contains(json_encode($detail),'SECRET-SUBJECT') && !str_contains(json_encode($detail),'session_id_hash'),'Authentication and session secrets excluded');
admin_test_assert(fc_admin_list($p,'users',"' OR 1=1 --",1)===[], 'Search remains parameterized');
admin_test_assert(fc_admin_list($p,'users','%',1)===[],'LIKE wildcards searched literally');
foreach (['crews','invitations','admins'] as $kind) { admin_test_assert(count(fc_admin_list($p,$kind,'',1))>0,"$kind listing executes"); }
fc_admin_crew_details($p,fc_admin_crew($p,'01ARZ3NDEKTSV4RRFFQ69G5FC1'));
fc_admin_authentication($p);fc_admin_system($p);fc_admin_dashboard($p);
ob_start();fc_admin_table([['name'=>'<script>alert(1)</script>']],['name'=>'Name']);$html=ob_get_clean();
admin_test_assert(!str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;'),'Stored markup escaped');
echo "ADMIN OPERATIONS: PASS\n";
