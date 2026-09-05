<?php
/** @var string $title */
/** @var string $contentView */
/** @var array<string,mixed> $currentUser */
/** @var array<string,mixed> $appContext */

$appSection = $appSection ?? 'overview';
$selectedCrew = $appContext['crew'] ?? null;
$selectedChallenge = $appContext['challenge'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
    <?php require fc_path('views/partials/head.php'); ?>
</head>
<body class="app-body">
    <?php $headerVariant = 'app'; require fc_path('views/partials/header.php'); ?>

    <main class="app-shell">
        <aside class="app-sidebar" aria-label="FitCrew navigation">
            <div class="sidebar-context-block">
                <img src="/assets/img/brand/fitcrew-crew-mark-reference.png" alt="" aria-hidden="true">
                <div>
                    <p class="sidebar-label">Current Crew</p>
                    <strong><?= $selectedCrew !== null ? fc_e((string) $selectedCrew['display_name']) : 'No Crew yet' ?></strong>
                    <span><?= $selectedChallenge !== null ? fc_e((string) $selectedChallenge['display_name']) : 'No Current Challenge' ?></span>
                </div>
            </div>

            <nav class="sidebar-nav" aria-label="Primary application sections">
                <a class="<?= $appSection === 'overview' ? 'is-current' : '' ?>" href="/app.php"><span aria-hidden="true">⌂</span> Overview</a>
                <a class="<?= $appSection === 'crew' ? 'is-current' : '' ?>" href="/crew.php"><span aria-hidden="true">◉</span> Crew</a>
                <a class="<?= $appSection === 'challenge' ? 'is-current' : '' ?>" href="/challenge.php"><span aria-hidden="true">▲</span> Challenge</a>
            </nav>

            <div class="sidebar-secondary">
                <a class="<?= $appSection === 'account' ? 'is-current' : '' ?>" href="/account.php"><span aria-hidden="true">○</span> Account</a>
                <a class="<?= $appSection === 'health' ? 'is-current' : '' ?>" href="/health/google/status.php"><span aria-hidden="true">♡</span> Health Connections</a>
                <form method="post" action="/logout.php">
                    <?= fc_csrf_input() ?>
                    <button type="submit"><span aria-hidden="true">↗</span> Sign Out</button>
                </form>
            </div>

            <div class="sidebar-product-note">
                <strong>Your Crew. Your Challenge. Your Progress.</strong>
                <span>Private competition built around governed truth.</span>
            </div>
        </aside>

        <section class="app-content">
            <?php require fc_path('views/partials/flash.php'); ?>
            <?php require fc_path($contentView); ?>
        </section>
    </main>

    <nav class="mobile-app-nav" aria-label="Mobile application navigation">
        <a class="<?= $appSection === 'overview' ? 'is-current' : '' ?>" href="/app.php"><span aria-hidden="true">⌂</span><small>Overview</small></a>
        <a class="<?= $appSection === 'crew' ? 'is-current' : '' ?>" href="/crew.php"><span aria-hidden="true">◉</span><small>Crew</small></a>
        <a class="<?= $appSection === 'challenge' ? 'is-current' : '' ?>" href="/challenge.php"><span aria-hidden="true">▲</span><small>Challenge</small></a>
        <button class="mobile-more-toggle" type="button" aria-expanded="false" aria-controls="mobile-more-menu"><span aria-hidden="true">•••</span><small>More</small></button>
    </nav>
    <div class="mobile-more-menu" id="mobile-more-menu" hidden>
        <a href="/account.php">Account</a>
        <a href="/health/google/status.php">Health Connections</a>
        <form method="post" action="/logout.php"><?= fc_csrf_input() ?><button type="submit">Sign Out</button></form>
    </div>

    <script src="/assets/js/app.js?v=<?= fc_e((string) $fitcrewAssetVersion) ?>" defer></script>
</body>
</html>
