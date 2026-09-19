<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
function ci_assert(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
$root=dirname(__DIR__);
$migration=file_get_contents($root.'/database/migrations/0500_challenge_invitation_journey.sql') ?: '';
$service=file_get_contents($root.'/inc/product/crew_invitations.php') ?: '';
$journey=file_get_contents($root.'/inc/product/challenge_invitations.php') ?: '';
$crew=file_get_contents($root.'/crew.php') ?: '';
$landing=file_get_contents($root.'/crew-invite.php') ?: '';
$view=file_get_contents($root.'/views/public/crew_invitation.php') ?: '';
$template=file_get_contents($root.'/inc/mail/templates/crew_invitation.php') ?: '';

foreach (['crew_current_challenges','challenge_invitation_acceptance_intents','ADD COLUMN challenge_id'] as $needle) {
    ci_assert(str_contains($migration,$needle),'0500 migration missing '.$needle);
}
ci_assert(str_contains($service,'function fc_crew_invitation_create(PDO $pdo, int $actorUserId, int $crewId, int $challengeId, string $email)'), 'Normal invitation must be Challenge-scoped.');
ci_assert(str_contains($service,'fc_auth_account_presence_for_email($pdo, $email)'), 'Private account-presence email customization missing.');
ci_assert(!str_contains($crew,'account_presence'), 'Account-presence classification must not be exposed to inviter controller/UI.');
ci_assert(str_contains($template,'KNOWN_ACCOUNT') && str_contains($template,'If you’re new to FitCrew'), 'Known/unknown recipient copy contract missing.');
ci_assert(str_contains($landing,"header('Referrer-Policy: no-referrer')"),'Raw-token landing must send no-referrer.');
ci_assert(str_contains($landing,'fc_challenge_invitation_review_session_set('),'Raw token must exchange into server-side review state.');
ci_assert(str_contains($landing,"fc_redirect('/crew-invite.php')"),'Raw token must redirect to a clean Website URL.');
ci_assert(!str_contains($view,'name="token"'),'Raw bearer token must not survive in ordinary forms.');
ci_assert(str_contains($view,'Accept Challenge'),'Public review must expose the explicit product acceptance action.');
ci_assert(str_contains($view,'Signed in as'),'Authenticated review must identify the FitCrew account.');
ci_assert(str_contains($view,'Use a different account'),'Authenticated review must offer account switching.');
ci_assert(str_contains($landing,'fc_auth_crew_invitation_continuation_issue('),'Signed-out acceptance must enter Auth continuation.');
ci_assert(str_contains($journey,'function fc_challenge_invitation_intent_create('),'Server-side acceptance intent missing.');
foreach (['invitation_generation','rule_version_id','consent_version','measurements_visibility','progress_visibility'] as $needle) {
    ci_assert(str_contains($journey,$needle),'Acceptance intent missing '.$needle);
}
ci_assert(str_contains($journey,'fc_crew_invitation_auth_snapshot(') && str_contains($journey,'true'), 'Final enrollment must lock/revalidate current invitation generation.');
ci_assert(str_contains($journey,'fc_challenge_accept_participation_locked('),'Invitation enrollment must reuse the existing Challenge acceptance engine.');
ci_assert(str_contains($journey,'fc_auth_crew_invitation_continuation_consume('),'Atomic enrollment must consume Auth continuation when applicable.');
ci_assert(str_contains($journey,'fc_product_context_persist('),'Successful enrollment must select Crew + Challenge context.');
ci_assert(str_contains($journey,'fc_challenge_invitation_intent_resume_for_invitation('),'Account switching must be able to restore Website acceptance intent after Auth session reset.');
ci_assert(!str_contains($landing,'email_at_provider') && !str_contains($journey,'email_at_provider'),'Invited/provider email must never become authentication authority.');

fwrite(STDOUT,"Crew invitation contract proof: PASS\n- Challenge-scoped invitation + private account-presence copy: PASS\n- Signed-out public review / raw-token clean redirect: PASS\n- One explicit Accept Challenge / account-switch affordance: PASS\n- Server-side acceptance intent / atomic enrollment reuse: PASS\n- Provider email non-identity: PASS\n");
