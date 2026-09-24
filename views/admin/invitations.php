<p class="admin-lead">Transport accepted means the mail service accepted the message. It does not confirm inbox delivery.</p>
<section class="product-card">
<?php fc_admin_search($query, 'Search invited email, Crew, Challenge or invitation ID'); ?>
<?php fc_admin_table($rows, ['invited_email'=>'Invited email','crew_name'=>'Crew','invitation_scope'=>'Scope','challenge_name'=>'Challenge','effective_status'=>'Invitation status','transport_status'=>'Transport','expires_at'=>'Expires'], ['column'=>'invited_email','route'=>'/admin/invitation.php']); ?>
<?php fc_admin_pager($page, $more, $query); ?>
</section>
