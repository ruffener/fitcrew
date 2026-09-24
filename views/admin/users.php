<section class="product-card">
<?php fc_admin_search($query, 'Search name, email or user ID'); ?>
<?php fc_admin_table($rows, ['display_name'=>'Name','contact_email'=>'Primary contact','platform_role_code'=>'Platform role','account_status'=>'Account status','created_at'=>'Created'], ['column'=>'display_name','route'=>'/admin/user.php']); ?>
<?php fc_admin_pager($page, $more, $query); ?>
</section>
