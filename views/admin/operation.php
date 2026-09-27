<?php
$catalog=fc_admin_operation_catalog($operation['kind']);
[$label,$effect]=$catalog[$operation['action']];
$back='/admin/'.$operation['kind'].'.php?id='.rawurlencode($operation['target']);
$spec=fc_admin_operation_form($operation);
$noEligibleOption=false; foreach($spec as $field) if(isset($field['options']) && $field['options']===[]) $noEligibleOption=true;
?>
<p><a href="<?= fc_e($back) ?>">Back to <?= fc_e($operation['kind']) ?> detail</a></p>
<section class="product-card admin-confirm">
<h2><?= fc_e($label) ?></h2>
<h3><?= $operation['stage']==='receipt'?'Reviewed state':'State when opened' ?></h3>
<?php fc_admin_facts(fc_admin_operation_current($operation)); ?>
<p class="admin-operation-effect"><?= fc_e($effect) ?></p>
<?php if($operation['stage']==='receipt'): ?>
<h3>Operation receipt</h3>
<?php fc_admin_facts(fc_admin_operation_receipt($operation['result'])); ?>
<?php if($operation['action']==='replacement_contact'): ?><p>Delivery acceptance does not prove mailbox control. Refresh the user detail after the recipient completes verification. Select the verified contact separately if it should become primary.</p><?php endif; ?>
<?php elseif($noEligibleOption): ?><p>No eligible selection is currently available. Review the record and its verified contacts, membership or participation.</p><a href="<?= fc_e($back) ?>">Cancel</a>
<?php else: ?>
<form method="post" action="/admin/operation.php" class="admin-operation-form">
<?= fc_csrf_input() ?>
<input type="hidden" name="ticket" value="<?= fc_e($operation['ticket']) ?>">
<input type="hidden" name="stage" value="<?= $operation['stage']==='edit'?'review':'execute' ?>">
<?php if($operation['stage']==='edit'): ?>
<?php foreach($spec as $name=>$field): ?>
<label for="op-<?= fc_e($name) ?>"><?= fc_e($field['label']) ?></label>
<?php if(isset($field['options'])): ?>
<select id="op-<?= fc_e($name) ?>" name="<?= fc_e($name) ?>" required>
<?php if(!array_key_exists((string)$field['value'],$field['options'])): ?><option value="">Choose…</option><?php endif; ?>
<?php foreach($field['options'] as $value=>$text): ?><option value="<?= fc_e((string)$value) ?>"<?= (string)$value===(string)$field['value']?' selected':'' ?>><?= fc_e($text) ?></option><?php endforeach; ?>
</select>
<?php else: ?>
<input id="op-<?= fc_e($name) ?>" name="<?= fc_e($name) ?>" type="<?= fc_e($field['type']??'text') ?>" value="<?= fc_e((string)$field['value']) ?>" maxlength="<?= (int)($field['max']??500) ?>"<?php if(isset($field['min_value'])): ?> min="<?= (int)$field['min_value'] ?>" max="<?= (int)$field['max_value'] ?>"<?php endif; ?><?= ($field['optional']??false)?'':' required' ?>>
<?php endif; ?>
<?php endforeach; ?>
<label for="op-reason">Administrator reason</label><input id="op-reason" name="reason" maxlength="500" required>
<div class="admin-actions"><button class="button button-primary" type="submit">Review change</button><a href="<?= fc_e($back) ?>">Cancel</a></div>
<?php else: ?>
<h3>Requested change</h3>
<?php
$changes=[];
foreach($spec as $name=>$field) $changes[$field['label']]=$field['options'][$operation['fields'][$name]]??$operation['fields'][$name];
$changes['Operation']=$label; $changes['Administrator reason']=$operation['reason'];
fc_admin_facts($changes);
?>
<label class="admin-check"><input type="checkbox" name="confirm" value="yes" required><span>I reviewed this change and its consequences for this <?= fc_e($operation['kind']) ?>.</span></label>
<div class="admin-actions"><button class="button button-primary" type="submit">Confirm <?= fc_e(strtolower($label)) ?></button><a href="<?= fc_e($back) ?>">Cancel</a></div>
<?php endif; ?>
</form>
<?php endif; ?>
</section>
