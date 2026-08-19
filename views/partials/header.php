<?php
$headerVariant = $headerVariant ?? 'public';
$isAppHeader = $headerVariant === 'app';
?>
<header class="site-header<?= $isAppHeader ? ' site-header-app' : '' ?>">
    <div class="site-header-inner">
        <a class="brand-lockup" href="/" aria-label="FitCrew Challenge home">
            <img class="brand-lockup-mark" src="/assets/img/brand/fitcrew-app-icon-navy-reference.png" alt="" aria-hidden="true">
            <span class="brand-lockup-type" aria-hidden="true">
                <strong><span>FIT</span><em>CREW</em></strong>
                <small>CHALLENGE</small>
            </span>
        </a>

        <?php if ($isAppHeader): ?>
            <div class="app-header-context" aria-label="Application context">
                <span class="context-label">Protected app shell</span>
                <span class="status-chip status-chip-neutral"><span aria-hidden="true">○</span> Placeholder</span>
            </div>
        <?php endif; ?>

        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="primary-navigation">
            <span class="sr-only">Toggle navigation</span>
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
        </button>

        <nav class="site-nav" id="primary-navigation" aria-label="Primary navigation">
            <?php if ($isAppHeader): ?>
                <a href="/app.php">Overview</a>
                <a href="/health/google/status.php">Health Connection</a>
                <form class="nav-logout-form" method="post" action="/logout.php">
                    <?= fc_csrf_input() ?>
                    <button class="nav-action nav-action-outline nav-logout-button" type="submit">Logout</button>
                </form>
            <?php else: ?>
                <a href="/#how-it-works">How It Works</a>
                <a href="/#private-by-design">Privacy</a>
                <a class="nav-action nav-action-outline" href="/login.php">Sign In</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
