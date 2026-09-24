<p><a href="/admin/crews.php">← Crews</a></p>
<section class="product-card"><h2><?= fc_admin_text($crew['display_name']) ?></h2>
<?php fc_admin_facts(['Crew ID'=>$crew['public_id'],'Status'=>$crew['crew_status'],'Description'=>$crew['description'],'Created'=>$crew['created_at']]); ?>
<p>Owner: <a href="/admin/user.php?id=<?= fc_e($crew['owner_public_id']) ?>"><?= fc_admin_text($crew['owner_name']) ?></a></p></section>
<section class="product-card"><h2>Members</h2><p>Latest 50 memberships.</p><?php fc_admin_table($detail['members'], ['display_name'=>'User','role_code'=>'Crew role','membership_status'=>'Status','joined_at'=>'Joined'], ['column'=>'display_name','route'=>'/admin/user.php']); ?></section>
<section class="product-card"><h2>Challenge summary</h2><p>Latest 50 Challenges.</p><?php fc_admin_table($detail['challenges'], ['display_name'=>'Challenge','lifecycle_status'=>'Lifecycle','operational_state'=>'Operations','active_participants'=>'Active participants','created_at'=>'Created']); ?></section>
<section class="product-card"><h2>Invitations</h2><p>Latest 50 invitations.</p><?php fc_admin_table($detail['invitations'], ['invited_email'=>'Email','invitation_scope'=>'Scope','challenge_name'=>'Challenge','invitation_status'=>'Stored status','transport_status'=>'Transport','expires_at'=>'Expires'], ['column'=>'invited_email','route'=>'/admin/invitation.php']); ?></section>
<section class="product-card"><h2>Audit history</h2><p>Latest 50 events associated with this Crew.</p><?php fc_admin_audit_table($detail['audit']); ?></section>
