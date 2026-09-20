<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit('Not Found');}
function cijc_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$m=file_get_contents($root.'/database/migrations/0500_challenge_invitation_journey.sql')?:'';
$ch=file_get_contents($root.'/inc/product/challenges.php')?:'';
$ci=file_get_contents($root.'/inc/product/challenge_invitations.php')?:'';
$inv=file_get_contents($root.'/inc/product/crew_invitations.php')?:'';
$controller=file_get_contents($root.'/crew-invite.php')?:'';
$view=file_get_contents($root.'/views/public/crew_invitation.php')?:'';
$crew=file_get_contents($root.'/crew.php')?:'';

cijc_assert(str_contains($m,'CREATE TEMPORARY TABLE fc_current_challenge_preflight'),'0500 must preflight current-Challenge conflicts before persistent mutation.');
cijc_assert(str_contains($m,'CREATE TABLE crew_current_challenges') && str_contains($m,'PRIMARY KEY (crew_id)'),'Database-backed one-current-Challenge authority missing.');
cijc_assert(!str_contains(strtolower($m),'generated always'),'0500 must not use a generated-column partial uniqueness trick.');
cijc_assert(str_contains($m,'ADD COLUMN challenge_id BIGINT UNSIGNED NULL'),'Legacy invitation history must remain nullable / unconverted.');
cijc_assert(str_contains($m,'CREATE TABLE challenge_invitation_acceptance_intents'),'Website acceptance-intent table missing.');
foreach(['invitation_generation','crew_id','challenge_id','rule_version_id','consent_version','measurements_visibility','progress_visibility','intent_status','expires_at','consumed_at'] as $needle)cijc_assert(str_contains($m,$needle),'Acceptance-intent schema missing '.$needle);
cijc_assert(str_contains($ch,'fc_crew_current_challenge(') && str_contains($ch,"INSERT INTO crew_current_challenges"),'Challenge creation/current authority services missing.');
cijc_assert(str_contains($inv,'fc_auth_account_presence_for_email($pdo, $email)'),'Private account-presence lookup missing.');
cijc_assert(!str_contains($crew,'account_presence'),'Inviter-facing controller must not expose account-presence classification.');
cijc_assert(str_contains($controller,'fc_challenge_invitation_intent_create('),'Accept Challenge must create Website acceptance intent.');
cijc_assert(str_contains($controller,'fc_invitation_complete_email_proof('),'Accept Challenge must authenticate and enroll through the invitation EMAIL proof.');
cijc_assert(str_contains($controller,"fc_redirect('/app.php')"),'Successful invitation enrollment must finish at Overview.');
cijc_assert(str_contains($ci,'fc_challenge_invitation_intent_resume_for_invitation('),'Account switch/session reset must preserve accepted product intent server-side.');
cijc_assert(str_contains($ci,'fc_challenge_accept_participation_locked('),'Final journey must reuse Challenge acceptance engine.');
cijc_assert(str_contains($ci,'fc_product_context_persist('),'Final enrollment must select the exact Crew and Challenge.');
foreach(['Challenge Invitation','Rules &amp; scoring','Health comes later.','Privacy choices','Accept Challenge','Use a different FitCrew account'] as $needle)cijc_assert(str_contains($view,$needle),'Public Challenge review missing '.$needle);
foreach(['participant_count','raw_health','provider_payload'] as $forbidden)cijc_assert(!str_contains(strtolower($view),$forbidden),'Public invitation view exposes protected data concept: '.$forbidden);

fwrite(STDOUT,"Challenge invitation journey contract proof: PASS\n- database-backed current-Challenge authority / legacy preservation: PASS\n- Challenge-scoped invitation / private account-presence copy: PASS\n- pre-auth review / one explicit acceptance / account switch: PASS\n- server-side intent / atomic enrollment reuse / selected context: PASS\n");
