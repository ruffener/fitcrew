<p class="admin-lead">Manage trusted operators. Only the Super Admin can grant or remove Admin access.</p>
<section class="product-card">
<?php fc_admin_search($query, 'Search a user by name, email or user ID'); ?>
<p><?= $query === '' ? 'Current Admin and Super Admin accounts. Search to find another user.' : 'Search results across all users. Open a user to review and change Admin access.' ?></p>
<?php fc_admin_table($rows, ['display_name'=>'Name','contact_email'=>'Primary contact','platform_role_code'=>'Platform role','account_status'=>'Account status'], ['column'=>'display_name','route'=>'/admin/user.php']); ?>
<?php fc_admin_pager($page, $more, $query); ?>
</section>
