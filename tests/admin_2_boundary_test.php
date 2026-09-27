<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function a2_boundary_assert(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo 'PASS: '.$label.PHP_EOL; }
foreach(['inc/admin/operations.php','inc/admin/operation_forms.php','inc/admin/controller.php','inc/admin/queries.php'] as $file) {
    $source=file_get_contents($root.'/'.$file);
    a2_boundary_assert(!preg_match('/\b(?:UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+(?:users|user_sessions|user_contact_emails|crews|crew_memberships|challenges|challenge_\w+)\b/i',$source),'No new direct domain mutation SQL: '.$file);
}
$source=file_get_contents($root.'/inc/admin/operations.php');
foreach(['fc_auth_user_profile_edit','fc_auth_user_sessions_end','fc_auth_user_suspend','fc_auth_user_restore','fc_auth_user_primary_contact_select','fc_auth_user_replacement_contact_initiate','fc_product_admin_crew_edit','fc_product_admin_crew_transfer_owner','fc_product_admin_crew_member_remove','fc_product_admin_crew_archive','fc_product_admin_crew_restore','fc_product_admin_challenge_edit_name','fc_product_admin_challenge_rule_draft_edit','fc_product_admin_challenge_participant_remove','fc_product_admin_challenge_end','fc_product_admin_challenge_archive','fc_product_admin_challenge_unarchive'] as $call) a2_boundary_assert(str_contains($source,$call.'('),'Governed owner call: '.$call);
a2_boundary_assert(!str_contains($source,'fc_audit_event_write('),'Admin does not duplicate owner mutation audits');
a2_boundary_assert(!str_contains($source,"hash('sha256'"),'Admin does not derive competing revisions');
$form=file_get_contents($root.'/inc/admin/operation_forms.php');
a2_boundary_assert(!preg_match("/'(?:lifecycle_status|platform_role_code|provider_subject|scoring_standard_code)'\\s*=>\\s*\\[/",$form),'No forbidden editable field descriptors');
$view=file_get_contents($root.'/views/admin/operation.php');
a2_boundary_assert(str_contains($view,'fc_csrf_input()') && str_contains($view,'name="confirm"') && str_contains($view,'>Cancel</a>'),'Confirmation/CSRF/Cancel visible');
a2_boundary_assert(!preg_match('/(?:print_r|var_dump|json_encode)\(/',$view),'Owner state never dumped into UI');
$layout=file_get_contents($root.'/views/admin/layout.php');$css=file_get_contents($root.'/admin/assets/admin.css');
a2_boundary_assert(str_contains($layout,'tabindex="-1"') && str_contains($css,'overflow-x: auto') && str_contains($css,':focus-visible') && str_contains($css,'@media (max-width: 850px)'),'Skip focus, table containment, keyboard focus and responsive shell retained');
echo "ADMIN-2 BOUNDARY CONTRACT: PASS\n";
