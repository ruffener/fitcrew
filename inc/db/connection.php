<?php

declare(strict_types=1);

function fc_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string) fc_env('DB_HOST', '127.0.0.1');
    $port = (string) fc_env('DB_PORT', '3306');
    $database = (string) fc_env('DB_DATABASE', 'fitcrew');
    $charset = (string) fc_env('DB_CHARSET', 'utf8mb4');
    $username = (string) fc_env('DB_USERNAME', 'root');
    $password = (string) fc_env('DB_PASSWORD', '');

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset);

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
