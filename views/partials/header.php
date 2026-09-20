<?php
$headerVariant = $headerVariant ?? 'public';
$isAppHeader = $headerVariant === 'app';
$headerCurrentUser = fc_current_user();
$headerCurrentEmail = null;
if ($headerCurrentUser !== null) {
    try {
        $headerCurrentEmail = fc_current_account_email(fc_db());
    } catch (Throwable) {
        $headerCurrentEmail = null;
    }
}
$headerDisplayName = $headerCurrentUser !== null
    ? (trim((string) ($headerCurrentUser['display_name'] ?? '')) ?: 'FitCrew account')
    : '';
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
            <div class="app-header-context" aria-label="Current FitCrew context">
                <span class="context-label">Current context</span>
                <?php if (isset($appContext['crew']) && $appContext['crew'] !== null): ?>
                    <a class="context-crew-link" href="/crew.php"><?= fc_e((string) $appContext['crew']['display_name']) ?></a>
                <?php else: ?>
                    <strong>No Crew yet</strong>
                <?php endif; ?>
                <span aria-hidden="true">/</span>
                <?php if (isset($appContext['challenge']) && $appContext['challenge'] !== null): ?>
                    <a class="context-challenge-link" href="/challenge.php?view=detail&amp;challenge=<?= fc_e(rawurlencode((string) $appContext['challenge']['public_id'])) ?>"><?= fc_e((string) $appContext['challenge']['display_name']) ?></a>
                <?php else: ?>
                    <em>No Challenge selected</em>
                <?php endif; ?>
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
                <a href="/crew.php">Crew</a>
                <a href="/challenge.php">Challenge</a>
                <a href="/health/google/status.php">Health Connections</a>
            <?php else: ?>
                <a href="/#how-it-works">How It Works</a>
                <a href="/#private-by-design">Privacy</a>
            <?php endif; ?>
        </nav>

        <div class="header-session">
            <?php if ($headerCurrentUser === null): ?>
                <a class="nav-action nav-action-outline header-sign-in" href="/login.php">Sign In</a>
            <?php else: ?>
                <details class="header-account-menu">
                    <summary class="header-account-trigger" aria-label="FitCrew account menu">
                        <span><?= fc_e($headerDisplayName) ?></span>
                        <?php if ($headerCurrentEmail !== null): ?><small><?= fc_e($headerCurrentEmail) ?></small><?php endif; ?>
                        <b aria-hidden="true">⌄</b>
                    </summary>
                    <div class="header-account-popover">
                        <div class="header-account-identity">
                            <strong><?= fc_e($headerDisplayName) ?></strong>
                            <?php if ($headerCurrentEmail !== null): ?><span><?= fc_e($headerCurrentEmail) ?></span><?php endif; ?>
                        </div>
                        <a href="/account.php">Account</a>
                        <form method="post" action="/logout.php">
                            <?= fc_csrf_input() ?>
                            <button type="submit">Sign Out</button>
                        </form>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </div>
</header>
