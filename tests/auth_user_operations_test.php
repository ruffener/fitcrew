<?php

declare(strict_types=1);
require __DIR__ . '/auth_user_operations_support.php';
ob_start();
try {
    $p = uop_db(); $f = uop_fixture($p);
    $reason = 'Correct profile at user request';
    uop_login($f['admin']);
    $r = uop_revision($p, $f['user']); $key = uop_key();
    $edited = fc_auth_user_profile_edit($p, $f['user']['public_id'], ['display_name'=>'Corrected','timezone'=>'America/New_York','locale'=>'en'], $r, $key, $reason);
    uop_assert($edited['replayed'] === false, 'Ordinary Admin edits USER profile');
    $repeat = fc_auth_user_profile_edit($p, $f['user']['public_id'], ['locale'=>'en','timezone'=>'America/New_York','display_name'=>'Corrected'], $r, $key, $reason);
    uop_assert($repeat['replayed'] && $repeat['audit_id'] === $edited['audit_id'], 'Exact replay returns original audit without another write');
    uop_denied(fn()=>fc_auth_user_profile_edit($p,$f['user']['public_id'],['display_name'=>'Different'],$r,$key,$reason), 'idempotency_conflict');
    uop_denied(fn()=>fc_auth_user_profile_edit($p,$f['user']['public_id'],['display_name'=>'Stale'],$r,uop_key(),$reason), 'stale_user_state');
    foreach (['platform_role_code','id','public_id','account_status','provider_subject','created_at','verified_at'] as $field) {
        uop_denied(fn()=>fc_auth_user_profile_edit($p,$f['user']['public_id'],[$field=>'unsafe'],$r,uop_key(),$reason), 'unexpected_fields');
    }
    foreach ([['display_name'=>''],['display_name'=>str_repeat('é',121)],['display_name'=>"bad\nname"],['timezone'=>'Not/AZone'],['locale'=>'fr']] as $bad) {
        $expected = isset($bad['locale']) ? 'unsupported_locale' : (isset($bad['timezone']) ? 'invalid_timezone' : 'invalid_display_name');
        uop_denied(fn()=>fc_auth_user_profile_edit($p,$f['user']['public_id'],$bad,$r,uop_key(),$reason),$expected);
    }
    uop_denied(fn()=>fc_auth_user_operations_snapshot($p,$f['admin']['public_id']), 'user_operation_denied');
    uop_denied(fn()=>fc_auth_user_operations_snapshot($p,$f['super']['public_id']), 'user_operation_denied');
    $r = uop_revision($p,$f['user']);
    foreach (['suspend','restore','primary_contact','replacement_contact'] as $op) {
        $fields = $op==='primary_contact' ? ['contact_id'=>1] : ($op==='replacement_contact' ? ['email'=>'test@example.test'] : []);
        uop_denied(fn()=>fc_user_ops_mutate($p,$f['user']['public_id'],$op,$fields,$r,uop_key(),$reason),'user_operation_denied');
    }
    $endKey = uop_key();
    $ended = fc_auth_user_sessions_end($p,$f['user']['public_id'],$r,$endKey,'Close current sessions');
    uop_assert($ended['revoked_sessions']===1 && fc_session_record_resolve_active($p,$f['user']['session'],3600)===null,'End sessions denies next authenticated request');
    fc_session_record_create($p,$f['user']['id'],$f['user']['identity_id'],'fresh-after-end',new DateTimeImmutable('+1 hour'),new DateTimeImmutable('+1 day'));
    $endReplay = fc_auth_user_sessions_end($p,$f['user']['public_id'],$r,$endKey,'Close current sessions');
    uop_assert($endReplay['replayed'] && fc_session_record_resolve_active($p,'fresh-after-end',3600)!==null,'Replay does not revoke sessions created after original end operation');
    uop_login($f['user']);
    uop_denied(fn()=>fc_auth_user_operations_snapshot($p,$f['other']['public_id']),'user_operation_denied');
    uop_login($f['super']);
    $superBefore = fc_user_find_by_id($p,$f['super']['id']);
    foreach (array_keys(FC_USER_OPERATION_EVENTS) as $op) {
        $fields = match($op) {'profile'=>['display_name'=>'No'],'primary_contact'=>['contact_id'=>1],'replacement_contact'=>['email'=>'test@example.test'],default=>[]};
        uop_denied(fn()=>fc_user_ops_mutate($p,$f['super']['public_id'],$op,$fields,str_repeat('a',64),uop_key(),$reason),'user_operation_denied');
    }
    uop_assert(fc_user_find_by_id($p,$f['super']['id']) === $superBefore,'Every Super Admin field and timestamp protected');
    fc_auth_user_profile_edit($p,$f['admin']['public_id'],['display_name'=>'Admin Edited'],uop_revision($p,$f['admin']),uop_key(),$reason);
    $susp = fc_auth_user_suspend($p,$f['admin']['public_id'],uop_revision($p,$f['admin']),uop_key(),'Account review');
    uop_assert($susp['revoked_sessions']===1 && fc_session_record_resolve_active($p,$f['admin']['session'],3600)===null,'Suspension atomically revokes and denies existing session');
    uop_denied(fn()=>fc_session_record_create($p,$f['admin']['id'],$f['admin']['identity_id'],'blocked-new',new DateTimeImmutable('+1 hour'),new DateTimeImmutable('+1 day')),'fitcrew_account_access_denied');
    fc_auth_user_restore($p,$f['admin']['public_id'],uop_revision($p,$f['admin']),uop_key(),'Review resolved');
    uop_assert(fc_user_find_by_id($p,$f['admin']['id'])['account_status']==='ACTIVE' && fc_session_record_resolve_active($p,$f['admin']['session'],3600)===null,'Restore preserves revocation and requires fresh sign-in');
    uop_denied(fn()=>fc_auth_user_restore($p,$f['admin']['public_id'],uop_revision($p,$f['admin']),uop_key(),'Repeat with new request'),'invalid_account_transition');
    $now=(string)$p->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn();
    $c1=fc_contact_email_create($p,$f['user']['id'],'one@example.test','TEST',true,'VERIFIED',$now);
    $c2=fc_contact_email_create($p,$f['user']['id'],'two@example.test','TEST',false,'VERIFIED',$now);
    $un=fc_contact_email_create($p,$f['user']['id'],'unverified@example.test','TEST');
    $foreign=fc_contact_email_create($p,$f['other']['id'],'foreign@example.test','TEST',true,'VERIFIED',$now);
    foreach ([$un,$foreign] as $bad) uop_denied(fn()=>fc_auth_user_primary_contact_select($p,$f['user']['public_id'],$bad['id'],uop_revision($p,$f['user']),uop_key(),'Select contact'),'verified_owned_contact_required');
    fc_auth_user_primary_contact_select($p,$f['user']['public_id'],$c2['id'],uop_revision($p,$f['user']),uop_key(),'Select verified mailbox');
    uop_assert((int)$p->query('SELECT id FROM user_contact_emails WHERE user_id='.$f['user']['id'].' AND is_primary_for_contact=1')->fetchColumn()===$c2['id'],'Only same-user verified contact becomes primary');
    uop_denied(fn()=>fc_auth_user_replacement_contact_initiate($p,$f['user']['public_id'],'foreign@example.test',uop_revision($p,$f['user']),uop_key(),'Replace contact'),'account_reconciliation_required');
    fc_auth_identity_create($p,$f['other']['id'],['provider_key'=>'EMAIL','issuer'=>'https://fitcrewchallenge.com/auth/email','provider_subject'=>'identity-owned@example.test']);
    uop_denied(fn()=>fc_auth_user_replacement_contact_initiate($p,$f['user']['public_id'],'identity-owned@example.test',uop_revision($p,$f['user']),uop_key(),'Replace contact'),'account_reconciliation_required');
    $init=fc_auth_user_replacement_contact_initiate($p,$f['user']['public_id'],'pending@example.test',uop_revision($p,$f['user']),uop_key(),'Verify new mailbox');
    uop_assert($init['delivery']==='ACCEPTED' && !isset($init['raw_token']) && fc_contact_email_find_verified_owner($p,'pending@example.test')===null,'Initiation sends via provider-neutral log transport without claiming mailbox');
    $proof=uop_verification($p,$f,'new@example.test');
    $authCount=(int)$p->query('SELECT COUNT(*) FROM user_auth_identities')->fetchColumn();
    $sessionCount=(int)$p->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn();
    $completed=fc_auth_user_contact_verification_complete($p,$proof['raw_token']);
    $replay=fc_auth_user_contact_verification_complete($p,$proof['raw_token']);
    uop_assert($completed['verified'] && $replay['replayed'],'Mailbox proof completes once and repeats safely');
    uop_assert((int)fc_contact_email_find_verified_owner($p,'new@example.test')['user_id']===$f['user']['id'],'Verified mailbox bound to original canonical user');
    uop_assert((int)$p->query('SELECT COUNT(*) FROM user_auth_identities')->fetchColumn()===$authCount && (int)$p->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn()===$sessionCount,'Mailbox confirmation creates no auth identity or session');
    uop_assert((int)$p->query('SELECT id FROM user_contact_emails WHERE user_id='.$f['user']['id'].' AND is_primary_for_contact=1')->fetchColumn()===$c2['id'],'Verification does not change primary contact');
    $proof=uop_verification($p,$f,'race@example.test');
    fc_contact_email_create($p,$f['other']['id'],'race@example.test','TEST',false,'VERIFIED',$now);
    uop_denied(fn()=>fc_auth_user_contact_verification_complete($p,$proof['raw_token']),'account_reconciliation_required');
    $oldProof=uop_verification($p,$f,'replaced@example.test');
    $proof=uop_verification($p,$f,'replacement@example.test');
    uop_denied(fn()=>fc_auth_user_contact_verification_complete($p,$oldProof['raw_token']),'contact_verification_invalid');
    $proof=uop_verification($p,$f,'stale@example.test');
    fc_auth_user_profile_edit($p,$f['user']['public_id'],['display_name'=>'Changed since request'],uop_revision($p,$f['user']),uop_key(),$reason);
    uop_denied(fn()=>fc_auth_user_contact_verification_complete($p,$proof['raw_token']),'contact_verification_invalid');
    $proof=uop_verification($p,$f,'expired@example.test');
    $p->prepare('UPDATE auth_contact_verifications SET created_at=UTC_TIMESTAMP(6)-INTERVAL 16 MINUTE,expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 MINUTE WHERE public_id=?')->execute([$proof['verification_public_id']]);
    uop_denied(fn()=>fc_auth_user_contact_verification_complete($p,$proof['raw_token']),'contact_verification_invalid');
    $proof=uop_verification($p,$f,'disabled@example.test');
    $p->prepare("UPDATE auth_contact_verifications SET status='DELIVERY_FAILED' WHERE public_id=?")->execute([$proof['verification_public_id']]);
    uop_denied(fn()=>fc_auth_user_contact_verification_complete($p,$proof['raw_token']),'contact_verification_invalid');
    // Failure at the success-audit insert must roll back the user mutation.
    $before=fc_user_find_by_id($p,$f['user']['id']); $r=uop_revision($p,$f['user']);
    $p->exec("CREATE TRIGGER uop_audit_failure BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.event_type='USER_PROFILE_EDITED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'; END IF; END");
    try { fc_auth_user_profile_edit($p,$f['user']['public_id'],['display_name'=>'Must roll back'],$r,uop_key(),$reason); throw new RuntimeException('Audit failure was ignored'); }
    catch (PDOException) { uop_assert(fc_user_find_by_id($p,$f['user']['id'])===$before,'Audit failure rolls back data and updated_at'); }
    $p->exec('DROP TRIGGER uop_audit_failure');
    $p->exec("SET time_zone='-07:00'");
    fc_auth_user_profile_edit($p,$f['user']['public_id'],['display_name'=>'UTC proof'],uop_revision($p,$f['user']),uop_key(),$reason);
    uop_assert($p->query('SELECT @@session.time_zone')->fetchColumn()==='+00:00','Mutation explicitly establishes UTC');
    $last=fc_user_ops_query($p,"SELECT * FROM audit_events WHERE event_type='USER_PROFILE_EDITED' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $details=json_decode($last['metadata_json'],true,512,JSON_THROW_ON_ERROR);
    uop_assert((int)$last['actor_user_id']===$f['super']['id'] && (int)$last['target_id']===$f['user']['id']
        && $last['outcome']==='SUCCESS' && $details['reason']===$reason && isset($details['before']['display_name'])
        && $details['after']['display_name']==='UTC proof','Success audit retains canonical actor/target, reason and allowlisted before/after');
    $meta=implode('\n',$p->query("SELECT metadata_json FROM audit_events WHERE event_type LIKE 'USER_%'")->fetchAll(PDO::FETCH_COLUMN));
    uop_assert(!str_contains($meta,$proof['raw_token']) && !str_contains($meta,$f['super']['session']),'No raw verification or session evidence in audit');
    uop_assert((int)$p->query("SELECT COUNT(*) FROM users WHERE platform_role_code='PLATFORM_SUPER_ADMIN'")->fetchColumn()===1,'Exactly one Super Admin preserved');
    echo "AUTH USER OPERATIONS: PASS\n";
} catch (Throwable $e) { if (isset($p) && $p->inTransaction()) $p->rollBack(); fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
