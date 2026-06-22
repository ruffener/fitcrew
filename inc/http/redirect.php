<?php

declare(strict_types=1);

function fc_redirect(string $path): never
{
    header('Location: ' . $path, true, 302);
    exit;
}
