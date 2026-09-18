<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__) . '/inc/bootstrap.php';

function aef_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function aef_query(PDO $pdo, string $sql, array $values): void
{
    $pdo->prepare($sql)->execute($values);
}
function aef_counts(PDO $pdo): array
{
    $counts=[];
    foreach (['users','user_auth_identities','user_contact_emails','user_sessions','crew_memberships','auth_invitation_admission_claims'] as $table) {
        $counts[$table]=(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    }
    return $counts;
}
function aef_denied(PDO $pdo, callable $call, string $reason): void
{
    $before=aef_counts($pdo);
    try { $call(); }
    catch (DomainException $error) {
        aef_assert($error->getMessage()===$reason, 'Unexpected denial: '.$error->getMessage());
        aef_assert(aef_counts($pdo)===$before, 'Denied authentication changed account/session/product records.');
        return;
    }
    throw new RuntimeException('Expected denial: '.$reason);
}
// Rollback-only fixtures. Real EMAIL issuance/replacement is independently
// exercised by email_magic_link_foundation_test; never sends mail here.
function aef_email(PDO $pdo, string $email, string $intent='LOGIN', ?int $expectedUser=null): array
{
    $token=fc_email_magic_link_token();
    $transaction=fc_auth_transaction_create($pdo,$intent,'EMAIL',$expectedUser,$token,'request-browser','APP_HOME',null,null,900);
    $flow=fc_email_magic_link_flow_hash($email,'fixture:'.bin2hex(random_bytes(8)));
    aef_query($pdo,'INSERT INTO email_magic_link_challenges (public_id,auth_transaction_id,issuer,email_subject,token_hash,flow_key_hash,active_flow_key_hash,expires_at) VALUES (?,?,?,?,?,?,?,?)',
        [fc_new_public_id(),$transaction['id'],FC_EMAIL_MAGIC_LINK_ISSUER,$email,fc_secret_evidence_hash($token),$flow,$flow,$transaction['expires_at']]);
    return ['id'=>(int)$pdo->lastInsertId(),'token'=>$token,'transaction'=>$transaction];
}
function aef_google(PDO $pdo, array $claims, string $session): array
{
    $state=bin2hex(random_bytes(32));
    $tx=fc_auth_transaction_create($pdo,'LOGIN','GOOGLE',null,$state,'google-browser','APP_HOME','google-nonce');
    return fc_google_complete_verified_login($pdo,$tx['public_id'],$state,'google-browser',$claims,$session);
}
function aef_claims(string $email, string $subject, ?string $hd=null): array
{
    $payload=['iss'=>'https://accounts.google.com','aud'=>'same-account-test','sub'=>$subject,
        'email'=>$email,'email_verified'=>true,'exp'=>time()+300,'nonce'=>'google-nonce','name'=>'Same account proof'];
    if ($hd!==null) $payload['hd']=$hd;
    return fc_google_validate_verified_payload($payload,'same-account-test',fc_secret_evidence_hash('google-nonce'),time());
}
$pdo=null;
try {
    $pdo=fc_db(); $pdo->beginTransaction();
    fc_auth_crew_invitation_continuation_clear_session();
    $suffix=bin2hex(random_bytes(8)); $email='same-account-'.$suffix.'@gmail.com';
    $_ENV['PRELAUNCH_AUTH_PROOF_MODE']='true'; $_ENV['PRELAUNCH_AUTH_ALLOWED_EMAILS']=$email;
    $_ENV['SESSION_IDLE_SECONDS']='3600'; $_ENV['SESSION_ABSOLUTE_SECONDS']='86400';
    $before=aef_counts($pdo);
    $claims=aef_claims($email,'same-account-'.$suffix);
    $google=aef_google($pdo,$claims,'google-first-'.$suffix); $userId=(int)$google['user']['id'];
    aef_assert($google['new_account'] && $google['user']['platform_role_code']==='USER', 'First Google login did not create one normal account.');
    aef_assert((int)fc_contact_email_find_verified_owner($pdo,strtoupper($email))['user_id']===$userId, 'Fresh Google proof did not establish canonical ownership.');
    aef_assert(fc_auth_account_presence_for_email($pdo,$email)==='KNOWN_ACCOUNT', 'Private presence cannot see verified ownership.');
    aef_assert(fc_auth_identity_find_oidc($pdo,'EMAIL',FC_EMAIL_MAGIC_LINK_ISSUER,$email)===null, 'Google login pre-created EMAIL identity without mailbox proof.');
    fc_session_revoke($pdo,'google-first-'.$suffix,'test_logout');
    $magic=aef_email($pdo,$email);
    $counts=aef_counts($pdo);
    aef_assert(fc_email_magic_link_inspect($pdo,$magic['token']), 'Valid magic link not inspectable.');
    aef_assert(aef_counts($pdo)===$counts, 'Opening/inspecting a link authenticated or modified an account.');
    $login=fc_email_magic_link_complete($pdo,$magic['token'],'request-browser','email-first-'.$suffix);
    aef_assert(!$login['new_account'] && (int)$login['user']['id']===$userId && $login['destination']==='/app.php', 'EMAIL did not sign in to the Google-created account.');
    aef_assert(aef_counts($pdo)['users']===$before['users']+1 && aef_counts($pdo)['user_auth_identities']===$before['user_auth_identities']+2, 'Duplicate account or identity created.');
    aef_denied($pdo,fn()=>fc_email_magic_link_complete($pdo,$magic['token'],'request-browser','replay-'.$suffix),'email_magic_link_invalid');
    $repeat=aef_email($pdo,$email);
    $again=fc_email_magic_link_complete($pdo,$repeat['token'],'other-browser','email-again-'.$suffix);
    aef_assert((int)$again['user']['id']===$userId && (int)$again['identity']['id']===(int)$login['identity']['id'], 'Cross-browser EMAIL duplicated/reassigned identity.');
    $googleAgain=aef_google($pdo,$claims,'google-again-'.$suffix);
    aef_assert(!$googleAgain['new_account'] && (int)$googleAgain['user']['id']===$userId, 'Google return changed account.');

    // Exact legacy shape: Google identity has a claim, but no verified contact.
    $legacyEmail='legacy-'.$suffix.'@gmail.com';
    $legacy=fc_user_create($pdo,'Legacy Google proof','ACTIVE','PLATFORM_ADMIN');
    $legacyIdentity=fc_auth_identity_create($pdo,$legacy['id'],[
        'provider_key'=>'GOOGLE','issuer'=>'https://accounts.google.com','provider_subject'=>'legacy-'.$suffix,
        'email_at_provider'=>$legacyEmail,'provider_email_verified'=>1,
        'email_verification_observed_at'=>new DateTimeImmutable('now',new DateTimeZone('UTC')),
    ]);
    aef_assert(fc_auth_account_presence_for_email($pdo,$legacyEmail)==='UNKNOWN', 'Stored provider claim was silently backfilled.');
    $legacyMagic=aef_email($pdo,$legacyEmail);
    aef_denied($pdo,fn()=>fc_email_magic_link_complete($pdo,$legacyMagic['token'],'request-browser','legacy-before-'.$suffix),'account_reconciliation_required');
    $legacyGoogle=aef_google($pdo,aef_claims($legacyEmail,'legacy-'.$suffix),'legacy-google-'.$suffix);
    aef_assert(!$legacyGoogle['new_account'] && (int)$legacyGoogle['user']['id']===$legacy['id'], 'Legacy Google account was recreated.');
    $legacyLogin=fc_email_magic_link_complete($pdo,$legacyMagic['token'],'request-browser','legacy-email-'.$suffix);
    aef_assert((int)$legacyLogin['user']['id']===$legacy['id'] && $legacyLogin['user']['platform_role_code']==='PLATFORM_ADMIN', 'Legacy account/role not retained.');

    // Workspace must also have signed authoritative evidence; third-party email does not.
    $workspaceEmail='workspace-'.$suffix.'@company.example';
    $_ENV['PRELAUNCH_AUTH_ALLOWED_EMAILS']=$workspaceEmail;
    $workspace=aef_google($pdo,aef_claims($workspaceEmail,'workspace-'.$suffix,'company.example'),'workspace-'.$suffix);
    aef_assert((int)fc_contact_email_find_verified_owner($pdo,$workspaceEmail)['user_id']===(int)$workspace['user']['id'], 'Workspace ownership was not established.');
    $untrustedEmail='third-party-'.$suffix.'@example.test';
    $_ENV['PRELAUNCH_AUTH_ALLOWED_EMAILS']=$untrustedEmail;
    aef_google($pdo,aef_claims($untrustedEmail,'third-party-'.$suffix),'third-party-'.$suffix);
    aef_assert(fc_auth_account_presence_for_email($pdo,$untrustedEmail)==='UNKNOWN', 'Third-party provider claim became verified ownership.');
    $untrusted=aef_email($pdo,$untrustedEmail);
    aef_denied($pdo,fn()=>fc_email_magic_link_complete($pdo,$untrusted['token'],'request-browser','untrusted-email-'.$suffix),'account_reconciliation_required');

    // Existing EMAIL and canonical ownership must agree; no merge or transfer.
    $other=fc_user_create($pdo,'Separate existing account');
    $otherEmail='conflict-'.$suffix.'@gmail.com';
    fc_contact_email_ensure_verified($pdo,$other['id'],$otherEmail,'TEST');
    $_ENV['PRELAUNCH_AUTH_ALLOWED_EMAILS']=$otherEmail;
    aef_denied($pdo,fn()=>aef_google($pdo,aef_claims($otherEmail,'new-conflict-'.$suffix),'conflict-new-'.$suffix),'account_reconciliation_required');
    aef_denied($pdo,fn()=>aef_google($pdo,aef_claims($otherEmail,'same-account-'.$suffix),'conflict-existing-'.$suffix),'account_reconciliation_required');
    $foreignIdentityEmail='foreign-identity-'.$suffix.'@gmail.com';
    fc_auth_identity_create($pdo,$other['id'],['provider_key'=>'EMAIL','issuer'=>FC_EMAIL_MAGIC_LINK_ISSUER,'provider_subject'=>$foreignIdentityEmail]);
    $_ENV['PRELAUNCH_AUTH_ALLOWED_EMAILS']=$foreignIdentityEmail;
    aef_denied($pdo,fn()=>aef_google($pdo,aef_claims($foreignIdentityEmail,'new-foreign-'.$suffix),'foreign-'.$suffix),'account_reconciliation_required');
    fc_auth_identity_create($pdo,$userId,['provider_key'=>'EMAIL','issuer'=>FC_EMAIL_MAGIC_LINK_ISSUER,'provider_subject'=>$otherEmail]);
    $foreign=aef_email($pdo,$otherEmail);
    aef_denied($pdo,fn()=>fc_email_magic_link_complete($pdo,$foreign['token'],'request-browser','foreign-canonical-'.$suffix),'account_reconciliation_required');

    // Status at completion remains authoritative; fresh proof cannot reactivate it.
    foreach (['SUSPENDED','DEACTIVATED'] as $status) {
        fc_user_set_account_status($pdo,$userId,$status);
        $blocked=aef_email($pdo,$email);
        aef_denied($pdo,fn()=>fc_email_magic_link_complete($pdo,$blocked['token'],'request-browser','status-'.$status.$suffix),'fitcrew_account_access_denied');
        aef_denied($pdo,fn()=>aef_google($pdo,$claims,'status-google-'.$status.$suffix),'fitcrew_account_access_denied');
    }
    fc_user_set_account_status($pdo,$userId,'ACTIVE');
    aef_query($pdo,"UPDATE user_auth_identities SET identity_status='REVOKED' WHERE id=?",[$login['identity']['id']]);
    $revoked=aef_email($pdo,$email);
    aef_denied($pdo,fn()=>fc_email_magic_link_complete($pdo,$revoked['token'],'request-browser','revoked-'.$suffix),'fitcrew_account_access_denied');
    aef_google($pdo,$claims,'google-revoked-email-'.$suffix);
    aef_assert(fc_auth_identity_find_oidc($pdo,'EMAIL',FC_EMAIL_MAGIC_LINK_ISSUER,$email)['identity_status']==='REVOKED', 'Google reactivated a revoked EMAIL identity.');

    // Retired LINK_IDENTITY challenges can never be completed as LOGIN.
    $retired=aef_email($pdo,$legacyEmail,'LINK_IDENTITY',$legacy['id']);
    aef_denied($pdo,fn()=>fc_email_magic_link_complete($pdo,$retired['token'],'request-browser','retired-'.$suffix),'email_magic_link_invalid');
    $expired=aef_email($pdo,$legacyEmail);
    aef_query($pdo,'UPDATE email_magic_link_challenges SET created_at=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 20 MINUTE),expires_at=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 5 MINUTE) WHERE id=?',[$expired['id']]);
    aef_denied($pdo,fn()=>fc_email_magic_link_complete($pdo,$expired['token'],'request-browser','expired-'.$suffix),'email_magic_link_invalid');
    $after=aef_counts($pdo);
    aef_assert($after['crew_memberships']===$before['crew_memberships'] && $after['auth_invitation_admission_claims']===$before['auth_invitation_admission_claims'], 'Ordinary login mutated product membership/admission.');
    $pdo->rollBack();
    fwrite(STDOUT,"Auth same-account email foundation: PASS\n- Google first login, logout, ordinary EMAIL login, repeat EMAIL/Google on one user\n- legacy Google account obtains ownership only after fresh Google proof\n- Workspace authority; third-party/stale provider claims remain insufficient\n- conflicts/status/revocation/replay/expiry/retired LINK proof fail closed\n- roles and product membership preserved; all fixtures rolled back\n");
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,'[FAIL] '.$error->getMessage().PHP_EOL); exit(1);
}
