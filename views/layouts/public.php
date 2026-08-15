<?php /** @var string $title */ /** @var string $contentView */ ?>
<!doctype html>
<html lang="en">
<head>
    <?php require fc_path('views/partials/head.php'); ?>
</head>
<body class="public-body">
    <?php $headerVariant = 'public'; require fc_path('views/partials/header.php'); ?>
    <main class="page-shell">
        <?php require fc_path('views/partials/flash.php'); ?>
        <?php require fc_path($contentView); ?>
    </main>
    <?php require fc_path('views/partials/footer.php'); ?>
    <script src="/assets/js/app.js?v=<?= fc_e((string) $fitcrewAssetVersion) ?>" defer></script>
</body>
</html>
