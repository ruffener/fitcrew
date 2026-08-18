<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

fc_flash('notice', 'Create Account and Sign In now share one provider-based account entry. Provider authentication is not active yet.');
fc_redirect('/login.php?intent=create');
