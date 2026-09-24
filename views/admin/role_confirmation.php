<section class="product-card admin-confirm"><h2><?= $action === 'make' ? 'Make Admin' : 'Remove Admin' ?></h2>
<?php fc_admin_facts(['User'=>$target['display_name'],'User ID'=>$target['public_id'],'Current role'=>fc_admin_role_label($oldRole),'New role'=>$action === 'make' ? 'Admin' : 'User']); ?>
<p><?= $action === 'make' ? 'This user will be able to view platform-wide operational and account information.' : 'This user will lose access to the Admin console on subsequent requests.' ?></p>
<form method="post" action="/admin/role.php" class="product-form">
    <?= fc_csrf_input() ?>
    <input type="hidden" name="target" value="<?= fc_e($target['public_id']) ?>">
    <input type="hidden" name="action" value="<?= fc_e($action) ?>">
    <input type="hidden" name="old_role" value="<?= fc_e($oldRole) ?>">
    <input type="hidden" name="confirmation" value="<?= fc_e($confirmation) ?>">
    <label class="admin-check"><input type="checkbox" name="confirm" value="yes" required> I confirm this role change for the user shown above.</label>
    <div class="admin-actions"><button class="button button-primary" type="submit"><?= $action === 'make' ? 'Confirm Make Admin' : 'Confirm Remove Admin' ?></button><a href="/admin/user.php?id=<?= fc_e($target['public_id']) ?>">Cancel</a></div>
</form>
</section>
