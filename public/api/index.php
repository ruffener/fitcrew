<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';

fc_response_code(501);
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'ok' => false,
    'message' => 'API behavior is not implemented in Phase 1 skeleton.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
