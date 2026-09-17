<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__) . '/inc/auth/account_presence.php';

final class FcAccountPresenceUnavailablePdo extends PDO
{
    public int $prepareCalls = 0;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepareCalls++;
        throw new PDOException('Simulated ownership lookup failure.');
    }
}

try {
    $pdo = new FcAccountPresenceUnavailablePdo();
    if (fc_auth_account_presence_for_email($pdo, '  ') !== 'UNKNOWN' || $pdo->prepareCalls !== 0) {
        throw new RuntimeException('Blank input must return UNKNOWN without querying.');
    }
    if (fc_auth_account_presence_for_email($pdo, 'unavailable@example.test') !== 'UNKNOWN'
        || $pdo->prepareCalls !== 1) {
        throw new RuntimeException('Unavailable evidence must return UNKNOWN without alternate identity searches.');
    }
    echo "Private Auth account-presence unit proof: PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
