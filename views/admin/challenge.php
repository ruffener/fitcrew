<p><a href="/admin/challenges.php">Back to Challenges</a></p>
<section class="product-card">
<h2><?= fc_admin_text($challenge['display_name']) ?></h2>
<?php fc_admin_facts(['Public ID'=>$challenge['public_id'],'Lifecycle'=>$challenge['lifecycle_status'],'Operational state'=>$challenge['operational_state'],'Created'=>$challenge['created_at'],'Completed'=>$challenge['completed_at'],'Effective end'=>$challengeOperations['owner_controls']['effective_end_at'],'Archived'=>$challengeOperations['owner_controls']['archived_at']]); ?>
<p>Crew: <a href="/admin/crew.php?id=<?= fc_e($challenge['crew_public_id']) ?>"><?= fc_admin_text($challenge['crew_name']) ?></a></p>
<p>Owner: <a href="/admin/user.php?id=<?= fc_e($challenge['owner_public_id']) ?>"><?= fc_admin_text($challenge['owner_name']) ?></a></p>
<?php fc_admin_operation_links('challenge',$challenge['public_id'],$challengeOperations); ?>
<?php if($challenge['lifecycle_status']==='COMPLETED'): ?><p>Competitive Rules and results are protected after completion. Only eligible name and archive presentation operations are available.</p><?php endif; ?>
</section>
<section class="product-card"><h2>Published Rules</h2><?php fc_admin_facts(fc_admin_rule_facts($challengeOperations['published_rule'])); ?><p>Published Rules and participant acceptance history remain unchanged by draft corrections.</p></section>
<section class="product-card"><h2>Draft Rules</h2><?php fc_admin_facts(fc_admin_rule_facts($challengeOperations['draft_rule'])); ?><p>Draft changes are not automatically published. Publication and acceptance use the established Challenge workflow.</p></section>
<section class="product-card"><h2>Participants</h2><p>Challenge participation is separate from Crew membership. Adding or returning participants requires invitation and explicit acceptance.</p><?php fc_admin_table($challengeOperations['participants'],['display_name'=>'User','user_public_id'=>'Public ID','participation_status'=>'Status','entry_kind'=>'Entry','joined_at'=>'Joined','withdrawn_at'=>'Withdrawn','removed_at'=>'Removed'],['column'=>'display_name','route'=>'/admin/user.php','id'=>'user_public_id']); ?></section>
<section class="product-card"><h2>Recent audit history</h2><?php fc_admin_audit_table($audit); ?></section>
