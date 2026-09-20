<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
function ciei_assert(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
$root=dirname(__DIR__);
$bootstrap=file_get_contents($root.'/inc/product/bootstrap.php') ?: '';
$service=file_get_contents($root.'/inc/product/crew_invitations.php') ?: '';
$controller=file_get_contents($root.'/crew-invite.php') ?: '';
$view=file_get_contents($root.'/views/public/crew_invitation.php') ?: '';
$auth=file_get_contents($root.'/inc/auth/invitation_email.php') ?: '';
$migration=file_get_contents($root.'/database/migrations/0510_invitation_email_auth_proofs.sql') ?: '';

ciei_assert(str_contains($bootstrap,"/auth/invitation_email.php"),'Website product bootstrap does not load the accepted Auth seam.');
ciei_assert(str_contains($migration,'CREATE TABLE auth_invitation_email_proofs'),'Auth 0510 proof ledger is missing.');
ciei_assert(str_contains($auth,'function fc_auth_invitation_email_register('),'Auth register interface missing.');
ciei_assert(str_contains($auth,'function fc_auth_invitation_email_capture('),'Auth capture interface missing.');
ciei_assert(str_contains($auth,'function fc_auth_invitation_email_complete('),'Auth completion interface missing.');
ciei_assert(str_contains($service,'fc_auth_invitation_email_register('),'Invitation issuance does not register EMAIL proof.');
ciei_assert(str_contains($service,"'/crew-invite.php?token='") === false,'Website transport must not generate query-token invitation URLs.');
ciei_assert(str_contains($controller,'history.replaceState'),'Fragment credential is not removed from browser history.');
ciei_assert(str_contains($controller,"action: 'capture_invitation_email'"),'Fragment capture action missing.');
ciei_assert(str_contains($controller,'fc_auth_invitation_email_capture('),'Website does not invoke Auth capture.');
ciei_assert(str_contains($controller,'fc_auth_invitation_email_complete('),'Website does not invoke Auth completion.');
ciei_assert(str_contains($controller,'fc_challenge_invitation_enroll($pdo, (int) $auth[\'user_id\'], false)'),'Website must enroll the user returned by Auth, not a cached pre-auth user.');
ciei_assert(str_contains($controller,'$pdo->beginTransaction();') && str_contains($controller,'$pdo->commit();') && str_contains($controller,'$pdo->rollBack();'),'Auth completion and product enrollment must share a caller-owned transaction.');
ciei_assert(str_contains($controller,"invitation_email_profile_required"),'New-account profile-required retry is missing.');
ciei_assert(str_contains($controller,"complete_profile"),'Profile completion POST is missing.');
ciei_assert(str_contains($controller,"fc_redirect('/app.php')"),'Successful journey must land on Overview.');
ciei_assert(str_contains($controller,"use_different_account"),'Explicit alternate-account path is missing.');
ciei_assert(str_contains($view,'Accept Challenge as'),'Recipient EMAIL account label is not prominent on acceptance.');
ciei_assert(str_contains($view,'Create Account &amp; Join Challenge'),'New-account completion must not require a second Challenge acceptance.');
ciei_assert(str_contains($view,'You do not need a second email sign-in link.'),'Review does not explain the simplified invitation EMAIL authentication path.');
ciei_assert(!str_contains($view,'Email me a sign-in link'),'Challenge invitation must not send the recipient through a second EMAIL magic link.');

fwrite(STDOUT,"Challenge invitation EMAIL integration proof: PASS\n- fragment credential registration/capture: PASS\n- explicit Accept authentication + atomic Website enrollment: PASS\n- existing/new account paths without second magic-link email: PASS\n- explicit alternate-account path retained: PASS\n- successful completion lands on Overview: PASS\n");
