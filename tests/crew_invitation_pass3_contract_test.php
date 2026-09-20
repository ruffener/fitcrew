<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
function p3_assert(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
$root=dirname(__DIR__);
$landing=file_get_contents($root.'/crew-invite.php') ?: '';
$service=file_get_contents($root.'/inc/product/crew_invitations.php') ?: '';
$journey=file_get_contents($root.'/inc/product/challenge_invitations.php') ?: '';
$crew=file_get_contents($root.'/crew.php') ?: '';
$view=file_get_contents($root.'/views/public/crew_invitation.php') ?: '';
$migration=file_get_contents($root.'/database/migrations/0320_crew_invitation_delivery_truth.sql') ?: '';

p3_assert(str_contains($landing,"header('Referrer-Policy: no-referrer')"),'Raw-token landing must send no-referrer.');
p3_assert(str_contains($service,'fc_auth_invitation_email_register('),'Fresh delivery must register the invitation bearer for EMAIL authentication.');
p3_assert(str_contains($landing,'history.replaceState'),'Fragment invitation bearer must leave browser history immediately.');
p3_assert(str_contains($landing,'fc_auth_invitation_email_capture('),'Fragment bearer must exchange through Auth capture.');
p3_assert(str_contains($landing,'fc_auth_invitation_email_complete('),'Explicit acceptance must authenticate through the invitation EMAIL proof.');
p3_assert(str_contains($landing,'fc_auth_crew_invitation_continuation_current($pdo)'),'Explicit different-account flow must retain minimal Auth continuation evidence.');
p3_assert(!str_contains($view,'name="token"'),'Ordinary acceptance form must not carry raw invitation token.');
p3_assert(str_contains($view,'Accept Challenge'),'Explicit Challenge acceptance must remain required.');
p3_assert(str_contains($journey,'fc_crew_invitation_auth_snapshot('),'Final Website validator missing.');
p3_assert((bool)preg_match('/fc_crew_invitation_auth_snapshot\s*\([^;]+?true\s*\)/s',$journey),'Enrollment must request locking invitation validation.');
p3_assert(str_contains($journey,'fc_auth_crew_invitation_continuation_consume('),'Enrollment must consume Auth continuation transactionally.');
p3_assert(str_contains($landing,'$pdo->beginTransaction();') && str_contains($landing,'$pdo->rollBack();') && str_contains($landing,'$pdo->commit();'),'Website controller must own one SQL enrollment transaction.');
foreach ([
    "FC_CREW_INVITATION_RATE_ISSUE_NAMESPACE = 'crew_invitation.issue'",
    "FC_CREW_INVITATION_RATE_RESEND_NAMESPACE = 'crew_invitation.resend'",
    "FC_CREW_INVITATION_RATE_INVALID_RAW_NAMESPACE = 'crew_invitation.invalid_raw'",
] as $needle) p3_assert(str_contains($service,$needle),'Invitation rate-limit namespace missing: '.$needle);
p3_assert((bool)preg_match('/function\s+fc_crew_invitation_rate_limit_issue\b.*?fc_rate_limit_consume\s*\(.*?10\s*,\s*900\s*\)/s',$service),'Issue rate-limit policy mismatch.');
p3_assert((bool)preg_match('/function\s+fc_crew_invitation_rate_limit_resend\b.*?fc_rate_limit_consume\s*\(.*?5\s*,\s*900\s*\)/s',$service),'Resend rate-limit policy mismatch.');
p3_assert((bool)preg_match('/function\s+fc_crew_invitation_rate_limit_invalid_raw\b.*?fc_rate_limit_consume\s*\(.*?20\s*,\s*900\s*\)/s',$service),'Invalid-token rate-limit policy mismatch.');
p3_assert(str_contains($crew,'fc_crew_invitation_rate_limit_issue(') && str_contains($crew,'fc_crew_invitation_rate_limit_resend('),'Invitation issue/resend limiters are not applied.');
p3_assert(str_contains($landing,'fc_crew_invitation_rate_limit_invalid_raw('),'Invalid raw-token limiter is not applied.');
foreach (['PENDING_SEND','TRANSPORT_ACCEPTED','TRANSPORT_FAILED','transport_driver','transport_message_id','transport_attempted_at'] as $needle) p3_assert(str_contains($migration,$needle),'Delivery truth migration missing: '.$needle);
p3_assert(str_contains($service,'TRANSPORT_FAILED') && str_contains($service,'TRANSPORT_ACCEPTED') && str_contains($service,'transport_message_id=:message_id'),'Transport truth recording missing.');
p3_assert(!str_contains($landing,'email_at_provider') && !str_contains($service,'email_at_provider'),'Provider email comparison must remain absent.');

fwrite(STDOUT,"Crew invitation PASS 3 contract proof: PASS\n- Fragment proof capture / pre-auth review / direct EMAIL authentication: PASS\n- Explicit atomic Challenge acceptance / no provider-email identity: PASS\n- Delivery truth semantics: PASS\n- Invitation rate-limit policies/call sites: PASS\n");
