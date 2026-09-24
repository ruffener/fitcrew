<section class="product-card">
<?php fc_admin_search($query, 'Search Crew name, owner or Crew ID'); ?>
<?php fc_admin_table($rows, ['display_name'=>'Crew','owner_name'=>'Owner','crew_status'=>'Status','active_members'=>'Active members','created_at'=>'Created'], ['column'=>'display_name','route'=>'/admin/crew.php']); ?>
<?php fc_admin_pager($page, $more, $query); ?>
</section>
