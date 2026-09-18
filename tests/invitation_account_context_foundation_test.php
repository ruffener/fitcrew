<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/product/bootstrap.php';
function iac_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function iac_denied(callable $call): void {
    try { $call(); } catch (DomainException|InvalidArgumentException) { return; }
    throw new RuntimeException('Unsafe account switch was permitted.');
}
$pdo=fc_db(); $pdo->beginTransaction();
try {
    $_ENV['SESSION_IDLE_SECONDS']='3600'; $_ENV['SESSION_ABSOLUTE_SECONDS']='86400';
    $suffix=bin2hex(random_bytes(8));
    $owner=fc_user_create($pdo,'Same Name'); $other=fc_user_create($pdo,'Same Name');
    $ownerEmail='current-'.$suffix.'@gmail.com'; $otherEmail='different-'.$suffix.'@example.test';
    fc_contact_email_create($pdo,$owner['id'],'unverified-'.$suffix.'@example.test','TEST',true);
    fc_contact_email_ensure_verified($pdo,$owner['id'],$ownerEmail,'TEST');
    fc_contact_email_ensure_verified($pdo,$other['id'],$otherEmail,'TEST');
    $identity=fc_auth_identity_create($pdo,$owner['id'],[
        'provider_key'=>'GOOGLE','issuer'=>'https://accounts.google.com','provider_subject'=>'switch-'.$suffix,
        'email_at_provider'=>'descriptive-only@example.test',
    ]);
    $session=fc_session_record_create_with_policy($pdo,$owner['id'],$identity['id'],session_id());
    iac_assert(fc_current_account_email($pdo)===$ownerEmail,'Account display used unverified/provider/other-account email.');
    $crew=fc_crew_create($pdo,$owner['id'],'Account context proof');
    $invitation=fc_crew_invitation_create($pdo,$owner['id'],$crew['id'],$otherEmail);
    $publicId=fc_new_public_id(); $binding=fc_auth_browser_binding();
    $pdo->prepare("INSERT INTO auth_invitation_continuations (public_id,purpose,invitation_public_id,invitation_generation,browser_session_binding_hash,continuation_status,authenticated_user_id,authenticated_session_id,authenticated_at,expires_at) VALUES(?,?,?,?,?,'AUTHENTICATED',?,?,CURRENT_TIMESTAMP(6),DATE_ADD(CURRENT_TIMESTAMP(6),INTERVAL 15 MINUTE))")
        ->execute([$publicId,FC_AUTH_CREW_INVITATION_PURPOSE,$invitation['public_id'],0,fc_secret_evidence_hash($binding),$owner['id'],$session['id']]);
    $_SESSION[FC_AUTH_CREW_INVITATION_SESSION_KEY]=$publicId;
    $continuation=fc_auth_crew_invitation_continuation_current($pdo);
    iac_assert($continuation!==null,'Fixture lacks authenticated continuation.');
    $signedInUser=$owner; $signedInEmail=fc_current_account_email($pdo); $alreadyMember=true;
    $display=fc_crew_invitation_continuation_display($pdo,$invitation['public_id'],0,$owner['id']);
    $originalInvitation=$invitation; $invitation=$display;
    ob_start(); require fc_path('views/public/crew_invitation.php'); $html=ob_get_clean();
    $dom=new DOMDocument(); @$dom->loadHTML($html); $xpath=new DOMXPath($dom);
    $button=$xpath->query('//form[@action="/crew-invite.php"]/button')->item(0);
    iac_assert($button!==null && $button->hasAttribute('disabled') && str_contains($button->textContent,$ownerEmail),'Existing-member button not disabled/identified.');
    iac_assert(str_contains($html,$ownerEmail) && str_contains($html,$otherEmail),'Current account and invitation destination not visible.');
    iac_assert($xpath->query('//form[@action="/auth/invitation/switch-account.php"]/input[@name="csrf_token"]')->length===1,'Switch is not a protected POST form.');
    $alreadyMember=false;
    ob_start(); require fc_path('views/public/crew_invitation.php'); $html=ob_get_clean();
    $dom=new DOMDocument(); @$dom->loadHTML($html); $xpath=new DOMXPath($dom);
    iac_assert(!$xpath->query('//form[@action="/crew-invite.php"]/button')->item(0)->hasAttribute('disabled'),'Eligible account cannot accept.');
    $invitation=$originalInvitation;

    $beforeSession=$_SESSION;
    $countUsers=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $countClaims=(int)$pdo->query('SELECT COUNT(*) FROM auth_invitation_admission_claims')->fetchColumn();
    $newBinding=bin2hex(random_bytes(32));
    // All rejection branches leave the original session and continuation intact.
    iac_denied(fn()=>fc_auth_crew_invitation_prepare_account_switch($pdo,$binding));
    $_SESSION['fitcrew_auth_browser_binding']=bin2hex(random_bytes(32));
    iac_denied(fn()=>fc_auth_crew_invitation_prepare_account_switch($pdo,$newBinding));
    $_SESSION=$beforeSession;
    foreach (['cancelled','rotated','expired','continuation_expired','revoked_session'] as $case) {
        $pdo->exec('SAVEPOINT switch_denial');
        if ($case==='cancelled') $pdo->prepare("UPDATE crew_invitations SET invitation_status='CANCELLED',cancelled_at=CURRENT_TIMESTAMP(6) WHERE public_id=?")->execute([$invitation['public_id']]);
        if ($case==='rotated') $pdo->prepare('UPDATE crew_invitations SET resend_count=resend_count+1 WHERE public_id=?')->execute([$invitation['public_id']]);
        if ($case==='expired') $pdo->prepare('UPDATE crew_invitations SET created_at=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 2 HOUR),expires_at=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 1 HOUR) WHERE public_id=?')->execute([$invitation['public_id']]);
        if ($case==='continuation_expired') $pdo->prepare('UPDATE auth_invitation_continuations SET created_at=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 2 HOUR),expires_at=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 1 HOUR) WHERE public_id=?')->execute([$publicId]);
        if ($case==='revoked_session') fc_session_revoke($pdo,session_id(),'proof');
        iac_denied(fn()=>fc_auth_crew_invitation_prepare_account_switch($pdo,$newBinding));
        $pdo->exec('ROLLBACK TO SAVEPOINT switch_denial');
        iac_assert($_SESSION===$beforeSession,'Rejected switch modified PHP session.');
    }
    $pdo->exec('SAVEPOINT switch_success');
    $prepared=fc_auth_crew_invitation_prepare_account_switch($pdo,$newBinding);
    iac_assert($_SESSION===$beforeSession,'Prepared switch published PHP session before commit.');
    $find=$pdo->prepare('SELECT * FROM auth_invitation_continuations WHERE public_id=?');
    $find->execute([$publicId]); $old=$find->fetch(PDO::FETCH_ASSOC);
    $find->execute([$prepared['public_id']]); $fresh=$find->fetch(PDO::FETCH_ASSOC);
    iac_assert($old['continuation_status']==='CONSUMED' && $fresh['continuation_status']==='ISSUED','Old authority not retired or new flow already authenticated.');
    iac_assert($fresh['authenticated_user_id']===null && $fresh['authenticated_session_id']===null && $fresh['auth_transaction_id']===null,'Account switch carried authenticated authority.');
    iac_assert($fresh['expires_at']<=$old['expires_at'] && $fresh['invitation_public_id']===$old['invitation_public_id'],'Switch changed invitation or extended expiry.');
    iac_assert(hash_equals($fresh['browser_session_binding_hash'],fc_secret_evidence_hash($newBinding)),'Fresh browser binding absent.');
    iac_assert(fc_session_record_resolve_active($pdo,session_id(),3600)===null,'Old session remains authorized.');
    iac_assert((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===$countUsers && (int)$pdo->query('SELECT COUNT(*) FROM auth_invitation_admission_claims')->fetchColumn()===$countClaims,'Switch created account or consumed admission.');
    $_SESSION=['fitcrew_auth_browser_binding'=>$newBinding,FC_AUTH_CREW_INVITATION_SESSION_KEY=>$prepared['public_id']];
    iac_assert(fc_auth_crew_invitation_continuation_pending_for_login($pdo)!==null,'New browser cannot resume sign-in.');
    foreach (['existing'=>$otherEmail, 'new'=>'new-mailbox-'.$suffix.'@aol.com'] as $kind=>$mailbox) {
        $pdo->exec('SAVEPOINT switched_login');
        $token=fc_email_magic_link_token();
        $tx=fc_auth_transaction_create($pdo,'LOGIN','EMAIL',null,$token,$newBinding,FC_AUTH_CREW_INVITATION_DESTINATION,null,null,600);
        fc_auth_crew_invitation_continuation_bind_login_transaction($pdo,$prepared['public_id'],$tx['id']);
        $flow=fc_email_magic_link_flow_hash($mailbox,$prepared['public_id']);
        $pdo->prepare('INSERT INTO email_magic_link_challenges(public_id,auth_transaction_id,issuer,email_subject,token_hash,flow_key_hash,active_flow_key_hash,expires_at) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([fc_new_public_id(),$tx['id'],FC_EMAIL_MAGIC_LINK_ISSUER,$mailbox,fc_secret_evidence_hash($token),$flow,$flow,$tx['expires_at']]);
        $login=fc_email_magic_link_complete($pdo,$token,$newBinding,'switched-'.$kind.'-'.$suffix);
        iac_assert((int)$login['user']['id']!==(int)$owner['id'] && $login['new_account']===($kind==='new'),'Switch reused old account or misclassified mailbox.');
        if ($kind==='existing') iac_assert((int)$login['user']['id']===(int)$other['id'],'Existing mailbox resolved to wrong account.');
        iac_assert($login['destination']==='/crew-invite.php','Switched login lost invitation return.');
        iac_assert(fc_crew_invitation_auth_snapshot($pdo,$invitation['public_id'],0)!==null,'Sign-in consumed the product invitation.');
        $membership=$pdo->prepare('SELECT COUNT(*) FROM crew_memberships WHERE crew_id=? AND user_id=?');
        $membership->execute([$crew['id'],$login['user']['id']]);
        iac_assert((int)$membership->fetchColumn()===0,'Authentication silently joined the Crew.');
        $pdo->exec('ROLLBACK TO SAVEPOINT switched_login');
    }
    $_SESSION['fitcrew_auth_browser_binding']=$binding;
    iac_assert(fc_auth_crew_invitation_continuation_pending_for_login($pdo)===null,'Old browser binding can use switched invitation.');
    $pdo->exec('ROLLBACK TO SAVEPOINT switch_success'); $_SESSION=$beforeSession;
    iac_assert(fc_session_record_resolve_active($pdo,session_id(),3600)!==null && fc_auth_crew_invitation_continuation_current($pdo)!==null,'Rollback failed to preserve original authority.');

    // Reproduce null context with one real accessible Challenge; never manufacture participation.
    $challenge=fc_challenge_create($pdo,$owner['id'],$crew['id'],'Sole Challenge',['planned_start_date'=>'2026-10-01']);
    fc_product_context_persist($pdo,$owner['id'],$crew['id'],null);
    $context=fc_product_context($pdo,$owner['id']);
    iac_assert((int)($context['challenge']['id']??0)===$challenge['id'],'Sole accessible Challenge not restored.');
    fc_product_context_select_crew($pdo,$owner['id'],$crew['id']);
    iac_assert((int)fc_product_context($pdo,$owner['id'])['challenge']['id']===$challenge['id'],'Selecting same Crew cleared Challenge.');
    $second=fc_challenge_create($pdo,$owner['id'],$crew['id'],'Legacy second Challenge',['planned_start_date'=>'2026-10-01']);
    fc_product_context_persist($pdo,$owner['id'],$crew['id'],null);
    iac_assert(fc_product_context($pdo,$owner['id'])['challenge']===null,'Ambiguous Challenge was guessed.');
    fc_product_context_select_challenge($pdo,$owner['id'],$challenge['id']);
    fc_product_context_select_crew($pdo,$owner['id'],$crew['id']);
    iac_assert((int)fc_product_context($pdo,$owner['id'])['challenge']['id']===$challenge['id'],'Explicit Challenge selection lost.');
    fc_crew_membership_add_existing($pdo,$owner['id'],$crew['id'],$other['id']);
    iac_assert(fc_product_context($pdo,$other['id'])['challenge']===null,'Context granted Challenge access without participation.');
    $pdo->rollBack();
    fwrite(STDOUT,"Invitation account/context foundation: PASS\n- self-only verified email and explicit account/destination labels; disabled existing-member button\n- switch revalidation, fresh anonymous binding, session revocation, replay denial, no expiry extension\n- existing and new EMAIL account after switch returns to invitation; no auto-membership
- cancellation/rotation/expiry/revocation denial and rollback-safe authority\n- sole accessible Challenge repair, explicit selection preservation, ambiguity/access guards\n- no account, admission, participation or invitation consumption by switching; fixtures rolled back\n");
} catch(Throwable $e) {
    if($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,'[FAIL] '.$e->getMessage().PHP_EOL); exit(1);
}
