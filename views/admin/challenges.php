<section class="product-card">
<?php fc_admin_search($query,'Search Challenges by name, Crew or public ID'); ?>
<?php fc_admin_table($rows,['display_name'=>'Challenge','crew_name'=>'Crew','owner_name'=>'Owner','lifecycle_status'=>'Lifecycle','operational_state'=>'Operational state','archived'=>'Archived','created_at'=>'Created'],['column'=>'display_name','route'=>'/admin/challenge.php']); ?>
<?php fc_admin_pager($page,$more,$query); ?>
</section>
