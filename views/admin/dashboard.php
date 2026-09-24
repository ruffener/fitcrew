<p class="admin-lead">A clear view of accounts, Crews and invitations across FitCrew.</p>
<div class="admin-stat-grid">
<?php foreach (['users'=>['Users','/admin/users.php'],'active_users'=>['Active accounts','/admin/users.php'],'crews'=>['Crews','/admin/crews.php'],'challenges'=>['Challenges','/admin/crews.php'],'pending_invitations'=>['Pending invitations','/admin/invitations.php'],'failed_transport'=>['Transport failures','/admin/invitations.php']] as $key=>$item): ?>
<a class="admin-stat product-card" href="<?= fc_e($item[1]) ?>"><span><?= fc_e($item[0]) ?></span><strong><?= (int) $stats[$key] ?></strong></a>
<?php endforeach; ?>
</div>
<section class="product-card"><h2>Recent audit history</h2><p>Latest 50 events. Detailed authentication material is excluded.</p><?php fc_admin_audit_table($audit); ?></section>
