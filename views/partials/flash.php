<?php foreach (fc_pull_flash() as $flash): ?>
    <div class="flash flash-<?= fc_e($flash['type'] ?? 'notice') ?>">
        <?= fc_e($flash['message'] ?? '') ?>
    </div>
<?php endforeach; ?>
