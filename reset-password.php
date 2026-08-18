<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

fc_flash('notice', 'FitCrew Challenge does not use FitCrew-managed passwords. Continue through account access.');
fc_redirect('/login.php');
