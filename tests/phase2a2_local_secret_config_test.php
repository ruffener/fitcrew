<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

require_once dirname(__DIR__) . '/inc/config/paths.php';
require_once dirname(__DIR__) . '/inc/config/env.php';

fc_load_env(fc_path('.env'));

require_once dirname(__DIR__) . '/inc/security/protected_secrets.php';

try {
    $key = fc_auth_transaction_secret_key();
    sodium_memzero($key);

    echo "AUTH_TRANSACTION_SECRET_KEY_B64: CONFIGURED / VALID 32-BYTE KEY\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "AUTH_TRANSACTION_SECRET_KEY_B64: MISSING / INVALID\n");
    fwrite(STDERR, "Generate a valid key with:\n");
    fwrite(STDERR, 'php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"' . PHP_EOL);
    fwrite(STDERR, "Place the generated value only in local .env as AUTH_TRANSACTION_SECRET_KEY_B64=<value>.\n");
    exit(1);
}
