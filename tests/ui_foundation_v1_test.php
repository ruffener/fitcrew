<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit('Not Found');}
function ui_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$css=file_get_contents($root.'/assets/css/app.css')?:'';
$view=file_get_contents($root.'/views/public/crew_invitation.php')?:'';
foreach(['--fc-type-display','--fc-type-title','--fc-type-body','--fc-page-gap','--radius-md','--fc-control-height','--fc-focus-ring'] as $token){ui_assert(str_contains($css,$token),'UI Foundation token missing: '.$token);}
foreach(['.fc-ui-title','.fc-ui-section-title','.fc-ui-body','.fc-ui-help','.fc-ui-card','.fc-ui-fieldset','.fc-notice'] as $component){ui_assert(str_contains($css,$component),'UI Foundation primitive missing: '.$component);}
ui_assert(str_contains($css,':focus-visible'),'Shared visible keyboard focus treatment missing.');
foreach(['fc-ui-title','fc-ui-section-title','fc-ui-body','fc-ui-help','fc-ui-card','fc-ui-fieldset','fc-notice'] as $role){ui_assert(str_contains($view,$role),'Challenge invitation must use semantic UI Foundation role: '.$role);}
ui_assert(!str_contains($view,'style="'),'Challenge invitation must not invent inline component sizing/styles.');
fwrite(STDOUT,"FitCrew UI Foundation v1 proof: PASS\n- typography/spacing/radius/control/focus tokens: PASS\n- card/notice/form primitives: PASS\n- invitation semantic-role usage: PASS\n- keyboard focus foundation: PASS\n");
