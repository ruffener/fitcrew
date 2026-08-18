<?php

declare(strict_types=1);

function fc_current_user(): ?array
{
    // Phase 1 skeleton placeholder only. Provider authentication is not implemented yet.
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

    fc_flash('notice', 'Please continue through FitCrew Challenge account access. Provider authentication is not active yet.');
    fc_redirect('/login.php');
}
