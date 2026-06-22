<?php /** @var string $title */ /** @var string $contentView */ ?>
<!doctype html>
<html lang="en">
<head>
    <?php require fc_path('views/partials/head.php'); ?>
</head>
<body>
    <?php require fc_path('views/partials/header.php'); ?>
    <main class="page-shell">
        <?php require fc_path('views/partials/flash.php'); ?>
        <?php require fc_path($contentView); ?>
    </main>
    <?php require fc_path('views/partials/footer.php'); ?>
    <script src="/assets/js/app.js" defer></script>
</body>
</html>
