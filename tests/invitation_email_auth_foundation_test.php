<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once fc_path('inc/product/bootstrap.php');
require_once fc_path('inc/auth/invitation_email.php');
function ie_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function ie_counts(PDO $pdo): array {
    $out=[];
    foreach (['users','user_auth_identities','user_contact_emails','user_sessions','auth_invitation_admission_claims','crew_memberships','challenge_participations','challenge_acceptance_records'] as $t) $out[$t]=(int)$pdo->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();
    return $out;
}
function ie_deny(PDO $pdo, callable $call, string $reason): void {
    $before=ie_counts($pdo);
    try {$call();} catch (DomainException $e) {
        ie_assert($e->getMessage()===$reason, 'Expected '.$reason.', received '.$e->getMessage());
        ie_assert(ie_counts($pdo)===$before,'Denied action changed identity/session/product records.'); return;
    }
    throw new RuntimeException('Expected denial '.$reason);
}
function ie_fixture(PDO $pdo, int $owner, int $crew, int $challenge, string $email, bool $register=true): array {
    $i=fc_crew_invitation_create($pdo,$owner,$crew,$challenge,$email);
    if ($register) {
        $url=fc_auth_invitation_email_register($pdo,$i['public_id'],0,$i['token']);
        ie_assert(str_contains($url,'#token=') && !str_contains($url,'?token='),'Auth bearer must stay outside HTTP query logs.');
    }
    $pdo->prepare("UPDATE crew_invitations SET transport_status='TRANSPORT_ACCEPTED',transport_driver='postmark',sent_at=CURRENT_TIMESTAMP(6) WHERE id=?")->execute([$i['id']]);
    return $i;
}
$pdo=fc_db();$pdo->beginTransaction();
try {
    $_ENV['SESSION_IDLE_SECONDS']='3600';$_ENV['SESSION_ABSOLUTE_SECONDS']='86400';
    $_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_ORIGIN']=fc_email_magic_link_expected_origin();
    $csrf=fc_csrf_token();$suffix=bin2hex(random_bytes(8));
    $owner=fc_user_create($pdo,'Invite owner');$crew=fc_crew_create($pdo,$owner['id'],'Email proof Crew');
    $challenge=fc_challenge_create($pdo,$owner['id'],$crew['id'],'Email proof Challenge',['planned_start_date'=>'2100-01-01']);
    $draft=fc_challenge_rule_current_draft($pdo,$challenge['id']);fc_challenge_rule_publish($pdo,$owner['id'],$challenge['id'],$draft['id']);
    $mail='verified-'.$suffix.'@gmail.com';$known=fc_user_create($pdo,'Existing user','ACTIVE','PLATFORM_ADMIN');
    fc_contact_email_ensure_verified($pdo,$known['id'],$mail,'TEST');
    fc_auth_identity_create($pdo,$known['id'],['provider_key'=>'GOOGLE','issuer'=>'https://accounts.google.com','provider_subject'=>'proof-'.$suffix]);
    $i=ie_fixture($pdo,$owner['id'],$crew['id'],$challenge['id'],$mail);
    $before=ie_counts($pdo);
    $_SERVER['REQUEST_METHOD']='GET';ie_deny($pdo,fn()=>fc_auth_invitation_email_capture($pdo,$i['token'],$csrf),'invitation_email_post_required');$_SERVER['REQUEST_METHOD']='POST';
    $_GET['token']=$i['token'];ie_deny($pdo,fn()=>fc_auth_invitation_email_capture($pdo,$i['token'],$csrf),'invitation_email_query_token_forbidden');unset($_GET['token']);
    $ctx=fc_auth_invitation_email_capture($pdo,$i['token'],$csrf);
    ie_assert(ie_counts($pdo)===$before,'Opening invitation authenticated or created account.');
    ie_assert(fc_auth_invitation_email_context($pdo)===$ctx,'Clean review cannot recover Auth context.');
    // Another browser may preview the same email; neither preview consumes it.
    $firstBrowser=$_SESSION;
    $_SESSION=['fitcrew_auth_browser_binding'=>bin2hex(random_bytes(32))];$scannerCsrf=fc_csrf_token();
    fc_auth_invitation_email_capture($pdo,$i['token'],$scannerCsrf);
    ie_assert(ie_counts($pdo)===$before,'Second-browser preview changed account state.');$_SESSION=$firstBrowser;
    ie_assert(!str_contains(json_encode($_SESSION),$i['token']),'Raw bearer retained in session.');
    $complete=fn()=>fc_auth_invitation_email_complete($pdo,$ctx['proof_public_id'],$i['public_id'],0,$csrf);
    $_SERVER['REQUEST_METHOD']='GET';ie_deny($pdo,$complete,'invitation_email_post_required');$_SERVER['REQUEST_METHOD']='POST';
    foreach (['null','https://attacker.example'] as $origin) { $_SERVER['HTTP_ORIGIN']=$origin;ie_deny($pdo,$complete,'invitation_email_origin_failed'); }
    $_SERVER['HTTP_ORIGIN']=fc_email_magic_link_expected_origin();
    ie_deny($pdo,fn()=>fc_auth_invitation_email_complete($pdo,$ctx['proof_public_id'],$i['public_id'],0,'wrong'),'invitation_email_csrf_failed');
    ie_deny($pdo,fn()=>fc_auth_invitation_email_complete($pdo,$ctx['proof_public_id'],$i['public_id'],1,$csrf),'invitation_email_context_mismatch');
    $receipt=$_SESSION[FC_AUTH_INVITATION_EMAIL_SESSION_KEY];unset($_SESSION[FC_AUTH_INVITATION_EMAIL_SESSION_KEY]);
    ie_deny($pdo,$complete,'invitation_email_review_expired');$_SESSION[FC_AUTH_INVITATION_EMAIL_SESSION_KEY]=$receipt;
    $_SESSION[FC_AUTH_INVITATION_EMAIL_SESSION_KEY]['expires_at']=time()-1;ie_deny($pdo,$complete,'invitation_email_review_expired');$_SESSION[FC_AUTH_INVITATION_EMAIL_SESSION_KEY]=$receipt;
    $binding=$_SESSION['fitcrew_auth_browser_binding'];$_SESSION['fitcrew_auth_browser_binding']=bin2hex(random_bytes(32));ie_deny($pdo,$complete,'invitation_email_review_expired');$_SESSION['fitcrew_auth_browser_binding']=$binding;
    foreach (['cancel','resend','recipient','failed_delivery','log_delivery','expired'] as $case) {
        $pdo->exec('SAVEPOINT invalid_proof');
        $sql=match($case){
            'cancel'=>"UPDATE crew_invitations SET invitation_status='CANCELLED',cancelled_at=CURRENT_TIMESTAMP(6) WHERE id=?",
            'resend'=>'UPDATE crew_invitations SET resend_count=resend_count+1 WHERE id=?',
            'recipient'=>"UPDATE crew_invitations SET invited_email='different@example.test' WHERE id=?",
            'failed_delivery'=>"UPDATE crew_invitations SET transport_status='TRANSPORT_FAILED' WHERE id=?",
            'log_delivery'=>"UPDATE crew_invitations SET transport_driver='log' WHERE id=?",
            'expired'=>'UPDATE crew_invitations SET created_at=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 2 HOUR),expires_at=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 1 HOUR) WHERE id=?'};
        $pdo->prepare($sql)->execute([$i['id']]);ie_deny($pdo,$complete,'invitation_email_invalid');$pdo->exec('ROLLBACK TO SAVEPOINT invalid_proof');
    }
    $pdo->exec('SAVEPOINT inactive');fc_user_set_account_status($pdo,$known['id'],'SUSPENDED');ie_deny($pdo,$complete,'fitcrew_account_access_denied');$pdo->exec('ROLLBACK TO SAVEPOINT inactive');
    $pdo->exec('SAVEPOINT wrong_account');
    $otherIdentity=fc_auth_identity_create($pdo,$owner['id'],['provider_key'=>'GOOGLE','issuer'=>'https://accounts.google.com','provider_subject'=>'owner-'.$suffix]);
    fc_session_record_create_with_policy($pdo,$owner['id'],$otherIdentity['id'],session_id());
    ie_deny($pdo,$complete,'invitation_email_account_switch_required');$pdo->exec('ROLLBACK TO SAVEPOINT wrong_account');
    $pdo->exec('SAVEPOINT existing_success');
    // Keep Website intent/session context through session rotation, and prove joint rollback.
    $review=fc_challenge_invitation_review($pdo,$i['public_id'],0);$intent=fc_challenge_invitation_intent_create($pdo,$review,[]);fc_challenge_invitation_intent_session_set($intent['public_id']);
    $_POST['email']='attacker@example.test';
    $oldSession=session_id();$r=$complete();
    ie_assert(session_id()!==$oldSession,'Session was not rotated.');
    ie_assert($r['user_id']===$known['id'] && !$r['new_account'],'Existing canonical owner not selected.');
    ie_assert(fc_user_find_by_id($pdo,$known['id'])['platform_role_code']==='PLATFORM_ADMIN','Role changed.');
    ie_assert(fc_challenge_invitation_intent_session_public_id()===$intent['public_id'],'Website intent lost during authentication.');
    ie_assert(ie_counts($pdo)['crew_memberships']===$before['crew_memberships'] && ie_counts($pdo)['challenge_participations']===$before['challenge_participations'],'Auth enrolled user itself.');
    ie_assert(fc_session_record_resolve_active($pdo,session_id(),3600)['user_id']===$known['id'],'Authenticated session not usable.');
    ie_deny($pdo,$complete,'invitation_email_invalid');
    fc_challenge_invitation_enroll($pdo,$r['user_id'],false);
    ie_assert(fc_challenge_participation_for_user($pdo,$challenge['id'],$r['user_id'])['participation_status']==='ACTIVE','Website enrollment failed.');
    $pdo->exec('ROLLBACK TO SAVEPOINT existing_success');
    ie_assert(ie_counts($pdo)===$before && fc_auth_invitation_email_read($pdo,$ctx['proof_public_id'])['consumed_at']===null,'Joint rollback did not restore proof/account/product records.');
    $pdo->exec('SAVEPOINT successful_retry');$r=$complete();ie_assert($r['user_id']===$known['id'],'Retry after rollback failed.');
    $pdo->prepare("UPDATE user_auth_identities SET identity_status='REVOKED',revoked_at=CURRENT_TIMESTAMP(6) WHERE user_id=? AND provider_key='EMAIL'")->execute([$known['id']]);
    // The independent mailbox proof must not resurrect a revoked EMAIL identity.
    $pdo->prepare("UPDATE crew_invitations SET invitation_status='CANCELLED',cancelled_at=CURRENT_TIMESTAMP(6) WHERE id=?")->execute([$i['id']]);
    $revoked=ie_fixture($pdo,$owner['id'],$crew['id'],$challenge['id'],$mail);$rc=fc_auth_invitation_email_capture($pdo,$revoked['token'],$csrf);
    ie_deny($pdo,fn()=>fc_auth_invitation_email_complete($pdo,$rc['proof_public_id'],$revoked['public_id'],0,$csrf),'fitcrew_account_access_denied');
    $pdo->exec('ROLLBACK TO SAVEPOINT successful_retry');

    $legacy=ie_fixture($pdo,$owner['id'],$crew['id'],$challenge['id'],'legacy-'.$suffix.'@example.test',false);
    ie_deny($pdo,fn()=>fc_auth_invitation_email_capture($pdo,$legacy['token'],$csrf),'invitation_email_not_registered');
    ie_deny($pdo,fn()=>fc_auth_invitation_email_register($pdo,$legacy['public_id'],0,$legacy['token']),'invitation_email_not_new_issuance');
    $new=ie_fixture($pdo,$owner['id'],$crew['id'],$challenge['id'],'new-'.$suffix.'@aol.com');$nc=fc_auth_invitation_email_capture($pdo,$new['token'],$csrf);
    $newComplete=fn(?string $name=null)=>fc_auth_invitation_email_complete($pdo,$nc['proof_public_id'],$new['public_id'],0,$csrf,$name);
    ie_deny($pdo,fn()=>$newComplete(),'invitation_email_profile_required');
    ie_deny($pdo,fn()=>$newComplete(str_repeat('x',121)),'invitation_email_profile_invalid');
    $pdo->exec('SAVEPOINT new_success');$newBefore=ie_counts($pdo);
    $newReview=fc_challenge_invitation_review($pdo,$new['public_id'],0);
    $newIntent=fc_challenge_invitation_intent_create($pdo,$newReview,[]);fc_challenge_invitation_intent_session_set($newIntent['public_id']);
    $r=$newComplete('New participant');
    ie_assert($r['new_account'] && fc_user_find_by_id($pdo,$r['user_id'])['display_name']==='New participant','New account/profile missing.');
    ie_assert(fc_user_find_by_id($pdo,$r['user_id'])['platform_role_code']==='USER','New account elevated.');
    ie_assert(ie_counts($pdo)['users']===$newBefore['users']+1 && ie_counts($pdo)['auth_invitation_admission_claims']===$newBefore['auth_invitation_admission_claims']+1,'Admission/account not singular.');
    ie_assert(ie_counts($pdo)['crew_memberships']===$newBefore['crew_memberships'],'New account auto-enrolled.');
    fc_challenge_invitation_enroll($pdo,$r['user_id'],false);
    ie_assert(fc_challenge_participation_for_user($pdo,$challenge['id'],$r['user_id'])['participation_status']==='ACTIVE','New account did not finish same accepted journey.');
    $pdo->exec('ROLLBACK TO SAVEPOINT new_success');ie_assert(ie_counts($pdo)===$newBefore,'New account rollback incomplete.');

    // Same descriptive provider email is never ownership and cannot cause auto-merge.
    $untrusted=fc_user_create($pdo,'Provider claim');
    fc_auth_identity_create($pdo,$untrusted['id'],['provider_key'=>'GOOGLE','issuer'=>'https://accounts.google.com','provider_subject'=>'other-'.$suffix,'email_at_provider'=>$new['email']]);
    ie_deny($pdo,fn()=>$newComplete('New participant'),'account_reconciliation_required');
    $audit=$pdo->query('SELECT metadata_json FROM audit_events')->fetchAll(PDO::FETCH_COLUMN);
    ie_assert(!str_contains(implode('\n',array_filter($audit)),$i['token']),'Raw token leaked to audit.');
    $pdo->rollBack();
    fwrite(STDOUT,"Invitation EMAIL proof: PASS\n- registered mail only; legacy/scanner GET cannot authenticate; no second email\n- POST/Origin/CSRF/browser/context/expiry/generation/cancellation/delivery guards\n- canonical Google owner gains EMAIL session without duplicate account or role change\n- required profile, canonical new-account admission, provider-claim reconciliation\n- session rotation preserves acceptance intent; Website enrolls exact returned user\n- replay denial and joint identity/session/proof/enrollment rollback; fixtures rolled back\n");
} catch (Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,'[FAIL] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine().PHP_EOL);exit(1);
}
