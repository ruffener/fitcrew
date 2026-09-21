<?php
// Auth presentation only: consume the same escaped flash messages as the shared view.
foreach (fc_pull_flash() as $flash):
    $noticeType = (string) ($flash['type'] ?? 'notice');
    $noticeType = in_array($noticeType, ['info', 'success', 'warning', 'error'], true) ? $noticeType : 'info';
    $noticeLabel = ['info' => 'Information', 'success' => 'Success', 'warning' => 'Please check', 'error' => 'Sign-in error'][$noticeType];
?>
    <div class="auth-notice fc-notice fc-notice-<?= fc_e($noticeType) ?>" role="<?= $noticeType === 'error' ? 'alert' : 'status' ?>" aria-atomic="true">
        <strong><?= fc_e($noticeLabel) ?></strong>
        <span><?= fc_e($flash['message'] ?? '') ?></span>
    </div>
<?php endforeach; ?>
