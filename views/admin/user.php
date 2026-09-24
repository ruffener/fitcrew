<p><a href="/admin/users.php">← Users</a></p>
<section class="product-card"><h2><?= fc_admin_text($user['display_name']) ?></h2>
<?php fc_admin_facts(['User ID'=>$user['public_id'],'Account status'=>$user['account_status'],'Platform role'=>fc_admin_role_label($user['platform_role_code']),'Created'=>$user['created_at'],'Updated'=>$user['updated_at'],'Timezone'=>$user['timezone'],'Onboarding completed'=>$user['onboarding_completed_at']]); ?>
<?php if (fc_admin_is_super($actor) && $user['platform_role_code'] !== 'PLATFORM_SUPER_ADMIN'): ?>
    <?php if ($user['platform_role_code'] === 'PLATFORM_ADMIN' || $user['account_status'] === 'ACTIVE'): ?>
    <a class="button button-secondary" href="/admin/role.php?id=<?= fc_e($user['public_id']) ?>&amp;action=<?= $user['platform_role_code'] === 'USER' ? 'make' : 'remove' ?>"><?= $user['platform_role_code'] === 'USER' ? 'Make Admin' : 'Remove Admin' ?></a>
    <?php endif; ?>
<?php endif; ?>
<?php if ($user['platform_role_code'] === 'PLATFORM_SUPER_ADMIN'): ?><p class="admin-note">Super Admin authority is protected. It cannot be changed in this console.</p><?php endif; ?>
</section>
<section class="product-card"><h2>Contact addresses</h2><?php fc_admin_table($detail['contacts'], ['email'=>'Email','verification_status'=>'Verification','is_primary_for_contact'=>'Primary contact','verified_at'=>'Verified']); ?></section>
<section class="product-card"><h2>Authentication methods</h2><?php fc_admin_table($detail['identities'], ['provider_key'=>'Method','identity_status'=>'Status','provider_email_verified'=>'Provider email verified','linked_at'=>'Linked','last_authenticated_at'=>'Last sign-in']); ?></section>
<section class="product-card"><h2>Sessions</h2><p>Latest 50 session records.</p><?php fc_admin_table($detail['sessions'], ['provider_key'=>'Method','session_status'=>'Status','created_at'=>'Created','last_seen_at'=>'Last seen','idle_expires_at'=>'Idle expiry','absolute_expires_at'=>'Absolute expiry']); ?></section>
<section class="product-card"><h2>Crew memberships</h2><p>Latest 50 memberships.</p><?php fc_admin_table($detail['memberships'], ['display_name'=>'Crew','role_code'=>'Crew role','membership_status'=>'Status','joined_at'=>'Joined'], ['column'=>'display_name','route'=>'/admin/crew.php']); ?></section>
<section class="product-card"><h2>Audit history</h2><p>Latest 50 events involving this user.</p><?php fc_admin_audit_table($detail['audit']); ?></section>
