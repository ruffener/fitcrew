<?php

declare(strict_types=1);

function fc_flash(string $type, string $message): void
{
    $_SESSION['fitcrew_flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function fc_pull_flash(): array
{
    $messages = $_SESSION['fitcrew_flash'] ?? [];
    unset($_SESSION['fitcrew_flash']);

    return is_array($messages) ? $messages : [];
}
