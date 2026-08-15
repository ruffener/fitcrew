<?php
/** @var string $title */

$fitcrewCssPath = fc_path('assets/css/app.css');
$fitcrewJsPath = fc_path('assets/js/app.js');
$fitcrewAssetVersion = max(
    is_file($fitcrewCssPath) ? (int) filemtime($fitcrewCssPath) : 1,
    is_file($fitcrewJsPath) ? (int) filemtime($fitcrewJsPath) : 1
);
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0D1B3D">
<title><?= fc_e(($title ?? 'FitCrew Challenge') . ' | FitCrew Challenge') ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/brand/favicon-reference-32.png">
<link rel="apple-touch-icon" href="/assets/img/brand/apple-touch-icon-reference.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css?v=<?= fc_e((string) $fitcrewAssetVersion) ?>">
