<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
function people_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$controller=file_get_contents($root.'/participants.php')?:'';
$view=file_get_contents($root.'/views/app/challenge/participants.php')?:'';
$crew=file_get_contents($root.'/views/app/crew/home.php')?:'';
$subnav=file_get_contents($root.'/views/app/challenge/subnav.php')?:'';
people_assert(str_contains($crew,'/participants.php?crew='),'Crew People navigation must target canonical People route.');
people_assert(str_contains($subnav,'/participants.php?challenge='),'Challenge Participants navigation must target canonical People route.');
people_assert(str_contains($controller,'fc_crew_memberships('),'People surface must consume Crew membership truth.');
people_assert(str_contains($controller,'fc_challenge_participants('),'People surface must consume Challenge participation truth.');
people_assert(str_contains($controller,'fc_crew_invitations_pending('),'People surface must consume canonical pending email invitations.');
foreach(['Crew Owner','Crew Member','Not Yet Joined Current Challenge','Withdrawn','Challenge Invitation Pending','Removed / Historical'] as $label) people_assert(str_contains($view,$label),'People state label missing: '.$label);
people_assert(str_contains($view,'Remove from Challenge'),'Challenge participation action missing.');
people_assert(str_contains($view,'Remove from Crew'),'Crew membership action missing.');
people_assert(str_contains($view,'Invite Someone New'),'Canonical new-person invitation action missing.');
people_assert(str_contains($view,'Invite Crew Member'),'Existing Crew-member invitation action missing.');
people_assert(!str_contains($view,'Invite Entire Crew'),'Unauthorized bulk-invitation action was added.');
people_assert(str_contains($controller,'fc_challenge_exit_participation('),'Challenge removal must reuse existing participation service.');
people_assert(str_contains($controller,'fc_crew_membership_remove_public('),'Crew removal must reuse existing membership service.');
people_assert(!str_contains($controller,'INSERT INTO challenge_participations'),'People surface must not manufacture participation directly.');
fwrite(STDOUT,"Unified People surface v1 proof: PASS\n- Crew and Challenge routes converge: PASS\n- Crew membership / Challenge participation remain distinct: PASS\n- current invitation/removal services reused: PASS\n- bulk invitation runtime not added: PASS\n");
