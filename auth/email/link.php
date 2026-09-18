<?php

declare(strict_types=1);

// Retired setup URL. Ordinary email sign-in now uses canonical verified ownership.
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('Location: /login.php', true, 303);
exit;
