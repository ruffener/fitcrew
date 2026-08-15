<?php /** @var string $title */ /** @var string $contentView */ ?>
<!doctype html>
<html lang="en">
<head>
    <?php require fc_path('views/partials/head.php'); ?>
</head>
<body class="auth-body">
    <?php $headerVariant = 'public'; require fc_path('views/partials/header.php'); ?>
    <main class="auth-shell">
        <?php require fc_path('views/partials/flash.php'); ?>
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
    <script src="/assets/js/app.js?v=<?= fc_e((string) $fitcrewAssetVersion) ?>" defer></script>
</body>
</html>
