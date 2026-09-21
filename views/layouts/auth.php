<?php /** @var string $title */ /** @var string $contentView */ ?>
<?php
// An acknowledgement left open before deployment remains dismissible.
$emailAckPending = in_array($_SESSION['fitcrew_email_request_ack'] ?? null, ['signin', 'link', 'retry'], true);
$emailAssetVersion = (string) max(
    filemtime(fc_path('assets/css/auth-email.css')),
    filemtime(fc_path('assets/js/auth-email.js'))
);
?>
<!doctype html>
<html lang="en">
<head>
    <?php require fc_path('views/partials/head.php'); ?>
    <link rel="stylesheet" href="/assets/css/auth-email.css?v=<?= fc_e($emailAssetVersion) ?>">
</head>
<body class="auth-body">
    <div<?= $emailAckPending ? ' inert' : '' ?>>
    <?php $headerVariant = 'public'; require fc_path('views/partials/header.php'); ?>
    <main class="auth-shell">
        <?php require fc_path('views/auth/notices.php'); ?>
        <div class="auth-layout">
            <section class="auth-brand-panel" aria-label="FitCrew Challenge brand">
                <img src="/assets/img/brand/fitcrew-logo-dark-reference.png" alt="FitCrew Challenge">
                <p>Your Crew. Your Challenge. Your Progress.</p>
                <div class="auth-brand-points" aria-label="FitCrew Challenge principles">
                    <span>Private</span>
                    <span>Accountable</span>
                    <span>Progress together</span>
                </div>
            </section>
            <div class="auth-content">
                <?php require fc_path($contentView); ?>
            </div>
        </div>
    </main>
    <?php require fc_path('views/partials/footer.php'); ?>
    </div>
    <?php if ($emailAckPending) require fc_path('views/auth/email_request_ack.php'); ?>
    <script src="/assets/js/auth-email.js?v=<?= fc_e($emailAssetVersion) ?>" defer></script>
    <script src="/assets/js/app.js?v=<?= fc_e((string) $fitcrewAssetVersion) ?>" defer></script>
    <?php if (!empty($googleAuthConfig['enabled'])): ?>
        <script src="https://accounts.google.com/gsi/client" async></script>
        <script src="/assets/js/google-auth.js?v=<?= fc_e((string) $fitcrewAssetVersion) ?>" defer></script>
    <?php endif; ?>
</body>
</html>
