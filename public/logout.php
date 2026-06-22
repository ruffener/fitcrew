<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

session_destroy();
fc_redirect('/');
