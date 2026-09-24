<p><a href="/admin/invitations.php">← Invitations</a></p>
<section class="product-card"><h2><?= fc_admin_text($invitation['invited_email']) ?></h2>
<p>Crew: <a href="/admin/crew.php?id=<?= fc_e($invitation['crew_public_id']) ?>"><?= fc_admin_text($invitation['crew_name']) ?></a></p>
<?php fc_admin_facts(['Scope'=>$invitation['invitation_scope'],'Challenge'=>$invitation['challenge_name'],'Challenge ID'=>$invitation['challenge_public_id'],'Challenge lifecycle'=>$invitation['challenge_lifecycle']]); ?>
<p class="admin-note">Invitation status reflects its stored state and expiry. Challenge availability and acceptance eligibility remain controlled by the Challenge journey.</p>
<?php fc_admin_facts(['Invitation ID'=>$invitation['public_id'],'Current status'=>$invitation['effective_status'],'Stored status'=>$invitation['invitation_status'],'Invited by'=>$invitation['inviter_name'],'Created'=>$invitation['created_at'],'Expires'=>$invitation['expires_at'],'Accepted by'=>$invitation['acceptor_name'],'Accepted'=>$invitation['accepted_at'],'Cancelled'=>$invitation['cancelled_at']]); ?></section>
<section class="product-card"><h2>Mail transport</h2>
<?php fc_admin_facts(['Transport status'=>$invitation['transport_status'],'Transport driver'=>$invitation['transport_driver'],'Last attempt'=>$invitation['transport_attempted_at'],'Sent timestamp'=>$invitation['sent_at'],'Resend count'=>$invitation['resend_count']]); ?>
<p class="admin-note">Transport accepted does not confirm inbox delivery. This console does not resend or cancel invitations.</p></section>
<section class="product-card"><h2>Audit history</h2><p>Latest 50 invitation-targeted events, where recorded by the owning service.</p><?php fc_admin_audit_table($audit); ?></section>
