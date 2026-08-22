<?php

declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// Microsoft response_mode=form_post intentionally lands here without relying on the
// SameSite=Lax FitCrew session cookie. This bridge performs no authentication work and
// no database writes. It reposts the short-lived code/state to same-origin completion,
// where the existing FitCrew session/browser binding is available again.
$code = trim((string) ($_POST['code'] ?? ''));
$state = trim((string) ($_POST['state'] ?? ''));
$providerError = trim((string) ($_POST['error'] ?? '')) !== '';
$nonce = base64_encode(random_bytes(18));

header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'nonce-{$nonce}'");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Completing Microsoft sign-in | FitCrew Challenge</title>
</head>
<body>
<form id="fitcrew-microsoft-complete" method="post" action="/auth/microsoft/complete.php">
    <input type="hidden" name="state" value="<?= htmlspecialchars($state, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <input type="hidden" name="code" value="<?= htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <input type="hidden" name="provider_error" value="<?= $providerError ? '1' : '0' ?>">
    <noscript><button type="submit">Continue to FitCrew Challenge</button></noscript>
</form>
<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">document.getElementById('fitcrew-microsoft-complete').submit();</script>
</body>
</html>
