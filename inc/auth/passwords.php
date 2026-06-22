<?php

declare(strict_types=1);

function fc_password_hash(string $password): string
{
    $pepper = (string) fc_env('PASSWORD_PEPPER', '');
    return password_hash($password . $pepper, PASSWORD_DEFAULT);
}

function fc_password_verify(string $password, string $hash): bool
{
    $pepper = (string) fc_env('PASSWORD_PEPPER', '');
    return password_verify($password . $pepper, $hash);
}
