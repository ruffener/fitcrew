<?php /** @var string $title */ /** @var string $contentView */ ?>
<!doctype html>
<html lang="en">
<head>
    <?php require fc_path('views/partials/head.php'); ?>
</head>
<body class="app-body">
    <?php $headerVariant = 'app'; require fc_path('views/partials/header.php'); ?>
    <main class="app-shell">
        <aside class="app-sidebar" aria-label="App navigation">
            <div class="sidebar-brand-block">
                <img src="/assets/img/brand/fitcrew-crew-mark-reference.png" alt="" aria-hidden="true">
                <div>
                    <p class="sidebar-label">Future context</p>
                    <strong>Your Crew</strong>
                    <span>Not configured</span>
                </div>
            </div>
            <nav class="sidebar-nav" aria-label="Application sections">
                <a class="is-current" href="/app.php"><span aria-hidden="true">⌂</span> Overview</a>
                <span class="sidebar-link-disabled"><span aria-hidden="true">◇</span> Groups <em>Planned</em></span>
                <span class="sidebar-link-disabled"><span aria-hidden="true">△</span> Challenges <em>Planned</em></span>
                <a href="/health/google/status.php"><span aria-hidden="true">♡</span> Health Connection</a>
            </nav>
            <div class="sidebar-note">
                <span class="status-chip status-chip-neutral"><span aria-hidden="true">○</span> Phase 1B</span>
                <p>Presentation shell only. Product behavior remains intentionally unavailable.</p>
            </div>
        </aside>
        <section class="app-content">
            <?php require fc_path('views/partials/flash.php'); ?>
            <?php require fc_path($contentView); ?>
        </section>
    </main>
    <script src="/assets/js/app.js?v=<?= fc_e((string) $fitcrewAssetVersion) ?>" defer></script>
</body>
</html>
