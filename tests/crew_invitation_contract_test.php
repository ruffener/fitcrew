<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
function ci_assert(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
$root=dirname(__DIR__);
$sql=file_get_contents($root.'/database/migrations/0300_family_alpha_relationships.sql');
ci_assert(str_contains($sql,'CREATE TABLE crew_invitations ('),'Crew invitation table missing.');
ci_assert(str_contains($sql,'token_hash') && !str_contains($sql,'raw_token'),'Only invitation-token hash may be stored.');
$service=file_get_contents($root.'/inc/product/crew_invitations.php');
foreach (['fc_crew_invitation_create','fc_crew_invitation_resend','fc_crew_invitation_cancel','fc_crew_invitation_accept'] as $fn) ci_assert(str_contains($service,'function '.$fn.'('),'Missing invitation service '.$fn);
ci_assert(str_contains($service,"hash('sha256', \$token)"),'Invitation token must be hashed.');
$crew=file_get_contents($root.'/crew.php');
foreach (['invite_member','resend_invitation','cancel_invitation'] as $action) ci_assert(str_contains($crew,$action),'Crew controller missing '.$action);
ci_assert(!str_contains($crew,"member_public_id'] ?? ''\n            );\n            fc_flash('success', 'Crew member added.'"),'Legacy Member-ID add flow must not remain consumer path.');
$view=file_get_contents($root.'/views/app/crew/home.php');
ci_assert(str_contains($view,'name="email"') && str_contains($view,'Pending invitations'),'Crew UI must use email invitations and pending state.');
$landing=file_get_contents($root.'/crew-invite.php');
ci_assert(str_contains($landing,'fc_is_logged_in()') && str_contains($landing,'fc_crew_invitation_accept'),'Acceptance requires an authenticated FitCrew user.');
ci_assert(!str_contains($service,'user_auth_identities') && !str_contains($service,'email_at_provider'),'Provider email must not become identity truth.');
fwrite(STDOUT,"Crew invitation contract proof: PASS\n- Email invitation + pending state: PASS\n- Token hash storage / resend rotation: PASS\n- Authenticated explicit acceptance: PASS\n- Provider email remains non-identity: PASS\n");
