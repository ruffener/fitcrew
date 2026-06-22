<?php /** @var string $title */ /** @var string $contentView */ ?>
<!doctype html>
<html lang="en">
<head>
    <?php require fc_path('views/partials/head.php'); ?>
</head>
<body class="app-body">
    <?php require fc_path('views/partials/header.php'); ?>
    <main class="app-shell">
        <aside class="app-sidebar" aria-label="App navigation">
            <p class="sidebar-label">App Shell</p>
            <a href="/app.php">Overview</a>
            <a href="/health/google/status.php">Health Connection</a>
        </aside>
        <section class="app-content">
            <?php require fc_path('views/partials/flash.php'); ?>
            <?php require fc_path($contentView); ?>
        </section>
    </main>
    <script src="/assets/js/app.js" defer></script>
</body>
</html>
