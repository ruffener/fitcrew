<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__) . '/inc/product/contracts.php';
require_once dirname(__DIR__) . '/inc/product/family_alpha.php';
function fa_contract_assert(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
$private=['measurements_visibility'=>'PRIVATE','progress_visibility'=>'PRIVATE'];
fa_contract_assert(fc_challenge_privacy_validate([])===$private,'Defaults must be private.');
try { fc_challenge_privacy_validate(['measurements_visibility'=>'PUBLIC']);throw new RuntimeException('Public visibility accepted.'); } catch (InvalidArgumentException) { }
$malicious=fc_challenge_privacy_validate(['raw_provider_data'=>'CHALLENGE','user_id'=>123]);
fa_contract_assert($malicious===$private,'Unsupported fields cannot create a new sharing category or identity.');
$rule=['planned_start_date'=>'2100-03-14','challenge_timezone'=>'America/New_York'];
fa_contract_assert(fc_family_entry_kind(['lifecycle_status'=>'LIVE'],$rule,new DateTimeImmutable('2100-03-13T20:00:00Z'))==='STANDARD','Entry label uses planned-start timing, not guessed scoring state.');
fa_contract_assert(fc_family_entry_kind(['lifecycle_status'=>'DRAFT'],$rule,new DateTimeImmutable('2100-03-15T20:00:00Z'))==='LATE','Late timing must be recorded without scoring.');
$root=dirname(__DIR__);
foreach (['participation.php','challenge-manage.php','participants.php','rules.php'] as $file) {
    $s=file_get_contents($root.'/'.$file);
    foreach (['fc_require_login()','fc_is_post()','fc_validate_csrf','challenge_public_id'] as $needle) fa_contract_assert(str_contains($s,$needle),$file.' missing '.$needle);
}
$personal=file_get_contents($root.'/participation.php');
fa_contract_assert(!str_contains($personal,"\$_POST['user_id']") && !str_contains($personal,"\$_POST['member_public_id']"),'Personal privacy/acceptance must not take a target user from POST.');
fa_contract_assert(str_contains($personal,"(\$_POST['accept_contract'] ?? '') === 'yes'"),'Personal acceptance must be explicit.');
$core=file_get_contents($root.'/inc/product/family_alpha.php');
fa_contract_assert(str_contains($core,'expectedOfferPublicId') && str_contains($core,'hash_equals'),'Acceptance must bind a pending offer, including resend/re-invite races.');
fa_contract_assert(!str_contains($core,'UPDATE challenge_acceptance_records'),'Acceptance receipts must not be rewritten.');
fa_contract_assert(!str_contains($core,'DELETE FROM challenge_participation_intervals'),'Intervals must not be deleted by Owner management.');
fa_contract_assert(!str_contains($core,"SET lifecycle_status") && !str_contains($core,'SET completed_at'),'End/archive/delete must not finalize competitive results.');
$model=substr($core,strpos($core,'function fc_challenge_public_participant_cards'));
fa_contract_assert(!str_contains($model,'SELECT p.*') && !str_contains($model,'SELECT u.*'),'Ordinary read model must be field allowlisted.');
$allSql='';foreach (glob($root.'/database/migrations/*.sql') as $file) $allSql.="\n".file_get_contents($file);
foreach (['official_daily_logs','raw_health_imports','health_provider_connections','scoring_results','standings','payments','challenge_monies'] as $table) {
    fa_contract_assert(!preg_match('/CREATE\s+TABLE\s+`?'.preg_quote($table,'/').'\b/i',$allSql),'Held runtime table introduced: '.$table);
}
$view=file_get_contents($root.'/views/app/challenge/participation.php');
fa_contract_assert(str_contains($view,'You can change these choices without accepting new Rules'),'Privacy control must be independent of new Rules acceptance.');
$create=file_get_contents($root.'/views/app/challenge/create.php');
fa_contract_assert(str_contains($create,'name="crew_public_id"'),'Creation must bind the form to its Crew, not mutable selected context.');
$sql=file_get_contents($root.'/database/migrations/0300_family_alpha_relationships.sql');
fa_contract_assert(str_contains($sql,"'LEGACY_SNAPSHOT'") && !str_contains($sql,'INSERT INTO challenge_acceptance_records'),'Migration must not fabricate legacy consent.');
foreach (['challenge_owner_controls','challenge_product_events','challenge_participant_offers','challenge_acceptance_records','challenge_participation_intervals','challenge_privacy_preferences','crew_invitations'] as $table) fa_contract_assert(str_contains($sql,'CREATE TABLE '.$table.' ('),'Expected table missing: '.$table);
fwrite(STDOUT,"Family Alpha A-D contract/static proof: PASS\n- Private defaults / no PUBLIC or raw-data option: PASS\n- Explicit current-user acceptance and privacy writes: PASS\n- Exact Challenge / Rule / pending offer binding: PASS\n- End/archive/delete separate from competitive finalization: PASS\n- No invented legacy consent or held measurement/scoring tables: PASS\n- Field-allowlisted current participant read model: PASS\n");
