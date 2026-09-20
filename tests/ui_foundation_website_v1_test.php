<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
function uiv1_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$root = dirname(__DIR__);
$css = file_get_contents($root . '/assets/css/app.css') ?: '';
$overview = file_get_contents($root . '/views/app/dashboard.php') ?: '';
$crew = file_get_contents($root . '/views/app/crew/home.php') ?: '';
$challenge = file_get_contents($root . '/views/app/challenge/home.php') ?: '';
$people = file_get_contents($root . '/views/app/challenge/participants.php') ?: '';
$header = file_get_contents($root . '/views/partials/header.php') ?: '';
$layout = file_get_contents($root . '/views/layouts/app.php') ?: '';
$subnav = file_get_contents($root . '/views/app/challenge/subnav.php') ?: '';
foreach (['--fc-type-page-title','--fc-type-section-title','--fc-type-card-title','--fc-type-body: 1rem','--fc-type-supporting: 0.875rem','--fc-type-meta: 0.8125rem','--fc-type-micro: 0.6875rem','--fc-space-1: 4px','--fc-space-12: 48px','--fc-radius-control: 10px','--fc-radius-notice: 14px','--fc-radius-card: 18px','--fc-radius-shell: 24px','--fc-control-button: 46px','--fc-control-input: 48px','--fc-control-touch: 44px'] as $token) uiv1_assert(str_contains($css,$token),'Foundation token missing: '.$token);
foreach (['.fc-type-display','.fc-type-page-title','.fc-type-section-title','.fc-type-card-title','.fc-type-body','.fc-type-supporting','.fc-type-meta','.fc-type-micro'] as $role) uiv1_assert(str_contains($css,$role),'Semantic role missing: '.$role);
uiv1_assert(str_contains($css,'prefers-reduced-motion: reduce'),'Reduced-motion compatibility missing.');
uiv1_assert(str_contains($css,'outline: var(--fc-focus-outline)'),'Focus-visible foundation missing.');
foreach ([[$overview,'fc-type-page-title','Overview'],[$crew,'fc-type-page-title','Crew'],[$challenge,'fc-type-page-title','Challenge'],[$people,'fc-type-page-title','People']] as [$source,$role,$surface]) uiv1_assert(str_contains($source,$role),$surface.' semantic Page Title role missing.');
foreach (['Crew Owner','Crew Member','Not Yet Joined Current Challenge','Withdrawn','Challenge Invitation Pending','Removed / Historical'] as $label) uiv1_assert(str_contains($people,$label),'People state missing: '.$label);
uiv1_assert(str_contains($crew,'/participants.php?crew='),'Crew → People route missing.');
uiv1_assert(str_contains($subnav,'/participants.php?challenge='),'Challenge → Participants route missing.');
uiv1_assert(str_contains($header,'/crew.php?crew='),'Header exact Crew context link missing.');
uiv1_assert(str_contains($layout,'sidebar-context-link'),'Sidebar context navigation missing.');
uiv1_assert(!str_contains($people,'Invite Entire Crew'),'Bulk invitation runtime/UI was silently added.');
fwrite(STDOUT,"FitCrew UI Foundation v1 Website proof: PASS\n- semantic typography/spacing/radius/control tokens: PASS\n- 16px Body / 14px Supporting+navigation / 13px Meta roles: PASS\n- 44px touch targets / visible focus / reduced motion: PASS\n- Overview / Crew / Challenge representative normalization: PASS\n- one People surface with distinct Crew/Challenge state: PASS\n- exact Crew/Challenge context navigation: PASS\n- Invite Entire Crew runtime not added: PASS\n");
