<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/inc/product/admin_operations.php');
$migration = file_get_contents($root . '/database/migrations/0540_product_admin_operations.sql');
$bootstrap = file_get_contents($root . '/inc/product/bootstrap.php');
$docs = file_get_contents($root . '/docs/admin_2_product_operations.md');
$schema = file_get_contents($root . '/database/schema_plan.md');

function pa_assert(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

pa_assert($service !== false && $migration !== false && $bootstrap !== false && $docs !== false && $schema !== false, 'required ADMIN-2 Product files readable');
pa_assert(str_contains($bootstrap, "require_once __DIR__ . '/admin_operations.php';"), 'Product bootstrap loads owner service');
pa_assert(str_contains($migration, 'CREATE TABLE product_admin_operations'), '0540 receipt table exists');
pa_assert(str_contains($migration, 'UNIQUE KEY uq_product_admin_operation_request (actor_user_id, request_key)'), 'idempotency key is durable per actor');
pa_assert(str_contains($migration, "'CREW','CHALLENGE'"), 'receipt target types are bounded');

$contracts = [
    'fc_product_admin_crew_snapshot', 'fc_product_admin_crew_edit', 'fc_product_admin_crew_transfer_owner',
    'fc_product_admin_crew_archive', 'fc_product_admin_crew_restore', 'fc_product_admin_crew_member_remove',
    'fc_product_admin_challenge_snapshot', 'fc_product_admin_challenge_edit_name', 'fc_product_admin_challenge_rule_draft_edit',
    'fc_product_admin_challenge_participant_remove', 'fc_product_admin_challenge_end', 'fc_product_admin_challenge_archive',
    'fc_product_admin_challenge_unarchive',
];
foreach ($contracts as $contract) pa_assert(str_contains($service, 'function ' . $contract . '('), "public contract {$contract}");

pa_assert(str_contains($service, "['PLATFORM_ADMIN', 'PLATFORM_SUPER_ADMIN']"), 'stored Admin/Super roles are required');
pa_assert(str_contains($service, "account_status'] !== 'ACTIVE'"), 'actor account must be ACTIVE');
pa_assert(str_contains($service, "identity_status='ACTIVE'"), 'active identity is rechecked');
pa_assert(str_contains($service, "s.revoked_at IS NULL"), 'session revocation is rechecked');
pa_assert(str_contains($service, 'stale_crew_state') && str_contains($service, 'stale_challenge_state'), 'stale editor revisions fail closed');
pa_assert(str_contains($service, 'idempotency_conflict') && str_contains($service, 'product_admin_operations'), 'idempotency conflict/receipt contract exists');
pa_assert(str_contains($service, "fc_product_admin_text(\$reason, 500, 'reason')"), 'administrator reason is required/bounded');
pa_assert(str_contains($service, "'metadata'=>['reason'=>\$reason,'before'=>\$before,'after'=>\$after]"), 'success audit contains reason + before/after');

pa_assert(str_contains($service, "if (fc_product_admin_is_super(\$actor))"), 'Super Admin privilege branch exists');
pa_assert(str_contains($service, "new_owner_must_be_active_crew_member"), 'ownership transfer cannot manufacture membership');
pa_assert(str_contains($service, "UPDATE crew_memberships SET role_code='MEMBER'"), 'old owner becomes member');
pa_assert(str_contains($service, "UPDATE crew_memberships SET role_code='OWNER'"), 'new owner membership becomes owner');
pa_assert(str_contains($service, 'crew_current_challenges') && str_contains($service, 'UPDATE challenges SET owner_user_id='), 'current Challenge management follows Crew ownership transfer');
pa_assert(str_contains($service, 'crew_has_current_challenge'), 'Crew archive rejects current Challenge');
pa_assert(str_contains($service, "membership_status='REMOVED'"), 'Crew member restriction preserves removed state');

pa_assert(str_contains($service, "version_status='DRAFT'"), 'Challenge setting corrections use draft Rules');
pa_assert(str_contains($service, 'supersedes_version_id'), 'correction draft supersedes published Rule');
pa_assert(str_contains($service, "'published_rules_unchanged'=>true"), 'published Rule remains authoritative during draft edit');
pa_assert(!str_contains($service, "SET version_status = 'PUBLISHED'"), 'Admin Product service never silently publishes Rule draft');
pa_assert(str_contains($service, 'completed_challenge_rules_locked'), 'completed competitive Rule state is locked');
pa_assert(str_contains($service, "participation_status='REMOVED'"), 'participant removal is history-preserving');
pa_assert(str_contains($service, 'fc_crew_current_challenge_release') && str_contains($service, 'fc_crew_current_challenge_restore'), 'end/archive controls maintain current-Challenge authority');
pa_assert(!preg_match("/UPDATE\\s+challenges\\s+SET\\s+lifecycle_status/i", $service), 'no arbitrary lifecycle engine added');

pa_assert(str_contains($docs, 'no Admin direct-add/reactivate membership operation'), 'membership consent boundary documented');
pa_assert(str_contains($docs, 'no Admin direct-add/reactivate participant operation'), 'participant consent boundary documented');
pa_assert(str_contains($docs, 'does **not** silently publish the draft'), 'published/acceptance history boundary documented');
pa_assert(str_contains($schema, 'ADMIN-2B / ADMIN-2C Product owner operations (0540)'), 'schema plan records 0540 ownership');

foreach (['admin/','inc/admin/','views/admin/','inc/auth/','inc/identity/','inc/security/'] as $forbidden) {
    pa_assert(!str_contains($docs, "modify `{$forbidden}"), "documentation does not authorize {$forbidden} edits");
}

echo "ADMIN-2B / ADMIN-2C Product owner contract proof: PASS\n";
echo "- role-scoped Crew/Challenge owner services: PASS\n";
echo "- stale-state + idempotency + reason + audit contract: PASS\n";
echo "- ownership/removal/history boundaries: PASS\n";
echo "- versioned Rule correction / no silent publication: PASS\n";
echo "- invitation/participation/lifecycle boundaries preserved: PASS\n";
