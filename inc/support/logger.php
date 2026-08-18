<?php

declare(strict_types=1);

function fc_log(string $level, string $message, array $context = []): void
{
    $context = fc_redact_sensitive_context($context);

    $line = sprintf(
        "[%s] %s: %s %s%s",
        date('c'),
        strtoupper($level),
        $message,
        $context !== [] ? json_encode($context, JSON_UNESCAPED_SLASHES) : '',
        PHP_EOL
    );

    $logFile = fc_storage_path('logs/app.log');
    file_put_contents($logFile, $line, FILE_APPEND);
}
