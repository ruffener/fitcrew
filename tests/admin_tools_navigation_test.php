<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not Found\n");
}

function atn_assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$layoutPath = $root . '/views/layouts/app.php';
$layoutSource = file_get_contents($layoutPath) ?: '';
$sessionSource = file_get_contents($root . '/inc/identity/session_records.php') ?: '';

$stubPath = tempnam(sys_get_temp_dir(), 'fitcrew-admin-nav-');
if ($stubPath === false) {
    throw new RuntimeException('Unable to create layout stub.');
}
file_put_contents($stubPath, "<?php /* layout test stub */ ?>\n");
$GLOBALS['atn_stub_path'] = $stubPath;

if (!function_exists('fc_path')) {
    function fc_path(string $path): string
    {
        return (string) $GLOBALS['atn_stub_path'];
    }
}
if (!function_exists('fc_e')) {
    function fc_e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('fc_csrf_input')) {
    function fc_csrf_input(): string
    {
        return '<input type="hidden" name="csrf_token" value="test">';
    }
}

/** @param array<string,mixed> $user */
function atn_render_layout(string $layoutPath, array $user): string
{
    $title = 'Navigation Test';
    $contentView = 'tests/layout_stub.php';
    $currentUser = $user;
    $appContext = ['crew' => null, 'challenge' => null];
    $appSection = 'overview';
    $fitcrewAssetVersion = 'test';

    ob_start();
    require $layoutPath;
    return (string) ob_get_clean();
}

$activeUser = [
    'user_id' => 1,
    'account_status' => 'ACTIVE',
    'platform_role_code' => 'USER',
];
$activeAdmin = [
    'user_id' => 2,
    'account_status' => 'ACTIVE',
    'platform_role_code' => 'PLATFORM_ADMIN',
];
$activeSuperAdmin = [
    'user_id' => 3,
    'account_status' => 'ACTIVE',
    'platform_role_code' => 'PLATFORM_SUPER_ADMIN',
];
$suspendedAdmin = [
    'user_id' => 4,
    'account_status' => 'SUSPENDED',
    'platform_role_code' => 'PLATFORM_ADMIN',
];
$unknownRole = [
    'user_id' => 5,
    'account_status' => 'ACTIVE',
    'platform_role_code' => 'ADMINISH',
];
$missingRole = [
    'user_id' => 6,
    'account_status' => 'ACTIVE',
];

$userHtml = atn_render_layout($layoutPath, $activeUser);
$adminHtml = atn_render_layout($layoutPath, $activeAdmin);
$superHtml = atn_render_layout($layoutPath, $activeSuperAdmin);
$suspendedHtml = atn_render_layout($layoutPath, $suspendedAdmin);
$unknownHtml = atn_render_layout($layoutPath, $unknownRole);
$missingHtml = atn_render_layout($layoutPath, $missingRole);

foreach ([$userHtml, $suspendedHtml, $unknownHtml, $missingHtml] as $html) {
    atn_assert(!str_contains($html, 'href="/admin/"'), 'Admin Tools leaked to an ineligible user.');
    atn_assert(!str_contains($html, '>Admin Tools<'), 'Admin Tools label leaked to an ineligible user.');
}

foreach ([$adminHtml, $superHtml] as $html) {
    atn_assert(substr_count($html, 'href="/admin/"') === 2, 'Eligible admin must receive desktop and mobile Admin Tools links.');
    atn_assert(substr_count($html, 'Admin Tools') === 2, 'Eligible admin must receive exactly two Admin Tools labels.');

    $firstAdmin = strpos($html, 'href="/admin/"');
    $firstLogout = strpos($html, '<form method="post" action="/logout.php">');
    atn_assert($firstAdmin !== false && $firstLogout !== false && $firstAdmin < $firstLogout, 'Desktop Admin Tools must appear above Sign Out.');

    $secondAdmin = strpos($html, 'href="/admin/"', $firstAdmin + 1);
    $secondLogout = strpos($html, '<form method="post" action="/logout.php">', $firstLogout + 1);
    atn_assert($secondAdmin !== false && $secondLogout !== false && $secondAdmin < $secondLogout, 'Mobile Admin Tools must appear above Sign Out.');
}

atn_assert(
    str_contains($layoutSource, "(\$currentUser['account_status'] ?? null) === 'ACTIVE'")
    && str_contains($layoutSource, "['PLATFORM_ADMIN', 'PLATFORM_SUPER_ADMIN']"),
    'Layout must gate Admin Tools by ACTIVE stored canonical platform role.'
);
foreach (['email_canonical', 'current_account_email', '@gmail.', '@aol.', '@hboe.'] as $emailSignal) {
    atn_assert(!str_contains($layoutSource, $emailSignal), 'Layout must not use email-based Admin checks: ' . $emailSignal);
}
atn_assert(str_contains($sessionSource, 'u.platform_role_code'), 'fc_current_user session resolution must include the canonical stored platform role.');

// Native anchors are keyboard reachable without custom tabindex or script behavior.
atn_assert(str_contains($adminHtml, '<a href="/admin/"><span aria-hidden="true">▦</span> Admin Tools</a>'), 'Desktop Admin Tools must be a native anchor.');
atn_assert(str_contains($adminHtml, '<a href="/admin/">Admin Tools</a>'), 'Mobile Admin Tools must be a native anchor.');
atn_assert(!str_contains($adminHtml, 'tabindex="-1"'), 'Admin Tools must not be removed from keyboard navigation.');

// Simulate consecutive page requests after a stored-role grant/revoke. The layout
// must derive visibility from the current request's $currentUser, not cached UI state.
$beforeGrant = atn_render_layout($layoutPath, $activeUser);
$afterGrant = atn_render_layout($layoutPath, $activeAdmin);
$afterRevoke = atn_render_layout($layoutPath, $activeUser);
atn_assert(!str_contains($beforeGrant, 'href="/admin/"'), 'USER pre-grant state must hide Admin Tools.');
atn_assert(str_contains($afterGrant, 'href="/admin/"'), 'Next request after grant must show Admin Tools.');
atn_assert(!str_contains($afterRevoke, 'href="/admin/"'), 'Next request after revoke must hide Admin Tools.');

@unlink($stubPath);

fwrite(STDOUT, "Admin Tools navigation proof: PASS\n");
fwrite(STDOUT, "- ACTIVE USER hidden: PASS\n");
fwrite(STDOUT, "- ACTIVE PLATFORM_ADMIN visible desktop/mobile: PASS\n");
fwrite(STDOUT, "- ACTIVE PLATFORM_SUPER_ADMIN visible desktop/mobile: PASS\n");
fwrite(STDOUT, "- missing/unrecognized/inactive role hidden: PASS\n");
fwrite(STDOUT, "- native keyboard-reachable links above Sign Out: PASS\n");
fwrite(STDOUT, "- grant/revoke reflected on next rendered request: PASS\n");
