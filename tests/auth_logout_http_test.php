<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Exercise the real endpoint, CSRF validation, PHP sessions and redirect guard
// through HTTP. Account lookup/revocation/audit are fixture services: this proof
// never loads .env, opens an application database, or contacts a provider.
$root = dirname(__DIR__);
$dir = sys_get_temp_dir() . '/fitcrew-logout-' . bin2hex(random_bytes(8));
mkdir($dir . '/inc', 0700, true);
mkdir($dir . '/sessions', 0700);
copy($root . '/logout.php', $dir . '/logout.php');
$bootstrap = <<<'PHP'
<?php
require __ROOT__ . '/inc/config/env.php';
require __ROOT__ . '/inc/auth/sessions.php';
require __ROOT__ . '/inc/auth/auth.php';
require __ROOT__ . '/inc/http/csrf.php';
require __ROOT__ . '/inc/http/request.php';
require __ROOT__ . '/inc/http/response.php';
require __ROOT__ . '/inc/http/redirect.php';
require __ROOT__ . '/inc/support/flash.php';
require __ROOT__ . '/inc/security/escape.php';
$_ENV['APP_ENV'] = 'test';
$_ENV['SESSION_NAME'] = 'fitcrew_logout_test';
$_ENV['SESSION_SECURE'] = 'false';
$_ENV['SESSION_IDLE_SECONDS'] = '3600';
$_ENV['SESSION_ABSOLUTE_SECONDS'] = '86400';
function fc_db() { return null; }
function fc_session_record_resolve_active($pdo, string $id, int $idle): ?array {
    return ($_SESSION['fixture_active'] ?? false)
        ? ['user_id' => 7, 'session_record_id' => 11, 'provider_key' => 'GOOGLE'] : null;
}
function fc_session_revoke($pdo, string $id, string $reason): bool {
    file_put_contents(__DIR__ . '/../effects.log', "revoke:$reason\n", FILE_APPEND);
    return true;
}
function fc_audit_event_write($pdo, array $event): void {
    file_put_contents(__DIR__ . '/../effects.log', 'audit:' . $event['event_type'] . "\n", FILE_APPEND);
}
fc_start_session();
PHP;
file_put_contents($dir . '/inc/bootstrap.php', str_replace('__ROOT__', var_export($root, true), $bootstrap));
file_put_contents($dir . '/fixture.php', <<<'PHP'
<?php
require __DIR__ . '/inc/bootstrap.php';
$_SESSION['fixture_active'] = ($_GET['active'] ?? '') === '1';
$_SESSION['fixture_preserved'] = 'untouched';
echo json_encode(['csrf' => fc_csrf_token()]);
PHP);
file_put_contents($dir . '/app.php', <<<'PHP'
<?php
require __DIR__ . '/inc/bootstrap.php';
fc_require_login();
echo json_encode(['csrf' => fc_csrf_token(), 'flash' => fc_pull_flash(), 'preserved' => $_SESSION['fixture_preserved'] ?? null]);
PHP);
file_put_contents($dir . '/login.php', <<<'PHP'
<?php
require __DIR__ . '/inc/bootstrap.php';
echo json_encode(['signed_in' => fc_is_logged_in(), 'flash' => fc_pull_flash()]);
PHP);

function logout_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function logout_http(string $base, string $path, ?string &$cookie, ?array $post = null): array {
    $headers = "Content-Type: application/x-www-form-urlencoded\r\n";
    if ($cookie !== null) $headers .= "Cookie: $cookie\r\n";
    $context = stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST', 'header' => $headers,
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5,
    ]]);
    $body = file_get_contents($base . $path, false, $context);
    $response = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $response[0] ?? '', $status);
    $location = null;
    foreach ($response as $header) {
        if (preg_match('/^Set-Cookie: (fitcrew_logout_test=[^;]*)/i', $header, $match)) $cookie = $match[1];
        if (stripos($header, 'Location: ') === 0) $location = substr($header, 10);
    }
    return ['status' => (int) ($status[1] ?? 0), 'location' => $location, 'body' => (string) $body, 'headers' => implode("\n", $response)];
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) throw new RuntimeException('Cannot reserve local HTTP proof port.');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $dir . '/sessions',
    '-d', 'session.gc_probability=0', '-S', $address, '-t', $dir],
    [0 => ['pipe', 'r'], 1 => ['file', $dir . '/server.log', 'a'], 2 => ['file', $dir . '/server.log', 'a']], $pipes);
if (!is_resource($process)) throw new RuntimeException('Cannot start isolated HTTP proof.');
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, .1);
        if ($probe !== false) { fclose($probe); $ready = true; break; }
        usleep(100000);
    }
    logout_assert($ready, 'HTTP proof server did not start.');
    $cookie = null;
    $fixture = json_decode(logout_http($base, '/fixture.php?active=1', $cookie)['body'], true);
    $csrf = $fixture['csrf'];

    $get = logout_http($base, '/logout.php', $cookie);
    logout_assert($get['status'] === 302 && $get['location'] === '/', 'GET must not sign out.');
    foreach ([[], ['csrf_token' => 'old-tab-token'], ['csrf_token' => ['malformed']]] as $post) {
        $rejected = logout_http($base, '/logout.php', $cookie, $post);
        logout_assert($rejected['status'] === 303 && $rejected['location'] === '/app.php', 'Active stale/missing/malformed token must recover to Overview.');
        logout_assert(str_contains($rejected['headers'], 'no-store'), 'Logout response must not be cached.');
        logout_assert(!is_file($dir . '/effects.log'), 'Invalid request revoked a session or wrote a success audit.');
        $fresh = json_decode(logout_http($base, '/app.php', $cookie)['body'], true);
        logout_assert($fresh['preserved'] === 'untouched' && $fresh['csrf'] === $csrf, 'Rejected request altered session authority.');
        logout_assert(str_contains($fresh['flash'][0]['message'], 'Please select Sign Out again'), 'Recovery notice missing.');
    }

    $valid = logout_http($base, '/logout.php', $cookie, ['csrf_token' => $csrf]);
    logout_assert($valid['status'] === 302 && $valid['location'] === '/', 'Fresh valid POST did not sign out immediately.');
    logout_assert(file_get_contents($dir . '/effects.log') === "revoke:user_logout\naudit:SESSION_REVOKED_LOGOUT\n", 'Logout revoke/audit contract changed.');
    logout_assert(str_contains($valid['headers'], 'Max-Age=0'), 'Logout did not expire the browser cookie.');
    $guard = logout_http($base, '/app.php', $cookie);
    logout_assert($guard['location'] === '/login.php', 'Signed-out browser retained protected access.');
    $effects = file_get_contents($dir . '/effects.log');

    // Repeated click from an old tab after logout; no second revocation.
    $stale = logout_http($base, '/logout.php', $cookie, ['csrf_token' => $csrf]);
    logout_assert($stale['status'] === 303 && $stale['location'] === '/login.php', 'Already signed-out stale tab must reach sign-in.');
    logout_assert(file_get_contents($dir . '/effects.log') === $effects, 'Stale replay repeated logout effects.');

    // Expired/missing PHP session: browser still submits the old form token.
    $cookie = 'fitcrew_logout_test=expired-session-that-no-longer-exists';
    $expired = logout_http($base, '/logout.php', $cookie, ['csrf_token' => $csrf]);
    logout_assert($expired['status'] === 303 && $expired['location'] === '/login.php', 'Expired PHP session must recover to sign-in.');
    $login = json_decode(logout_http($base, '/login.php', $cookie)['body'], true);
    logout_assert($login['signed_in'] === false, 'Expired session became authenticated.');
    logout_assert(file_get_contents($dir . '/effects.log') === $effects, 'Expired invalid form changed logout effects.');

    // Database timeout while PHP session/CSRF remain valid still permits logout.
    $timeout = json_decode(logout_http($base, '/fixture.php?active=0', $cookie)['body'], true);
    $validExpired = logout_http($base, '/logout.php', $cookie, ['csrf_token' => $timeout['csrf']]);
    logout_assert($validExpired['status'] === 302 && $validExpired['location'] === '/', 'Expired authentication with valid CSRF must complete logout.');
    logout_assert(file_get_contents($dir . '/effects.log') === $effects . "revoke:user_logout\n", 'Expired session must not receive a false authenticated logout audit.');
    echo "Auth logout HTTP proof: PASS\n";
    echo "- missing/invalid/malformed CSRF preserves active session; fresh retry succeeds\n";
    echo "- expired PHP session and already-signed-out replay recover to sign-in\n";
    echo "- valid POST revokes/destroys session; GET does not log out; no-store recovery\n";
    echo "- real endpoint/CSRF/PHP sessions; isolated account-service fixtures, no database writes\n";
} finally {
    fclose($pipes[0]);
    proc_terminate($process);
    proc_close($process);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $path) $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    rmdir($dir);
}
