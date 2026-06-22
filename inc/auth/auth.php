<?php

declare(strict_types=1);

function fc_current_user(): ?array
{
    // Phase 1 skeleton placeholder only. Real account lookup is not implemented yet.
    return $_SESSION['fitcrew_user'] ?? null;
}

function fc_is_logged_in(): bool
{
    return fc_current_user() !== null;
}

function fc_require_login(): void
{
    if (fc_is_logged_in()) {
        return;
    }

    fc_flash('notice', 'Please sign in to continue. Account behavior is planned for a later approved phase.');
    fc_redirect('/login.php');
}
