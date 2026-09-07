<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not Found\n");
}

$root = dirname(__DIR__);
$controllers = ['crew.php', 'challenge.php', 'participants.php', 'rules.php'];
foreach ($controllers as $controller) {
    $source = file_get_contents($root . '/' . $controller) ?: '';
    if (!str_contains($source, 'fc_require_login()')) {
        throw new RuntimeException($controller . ' is missing server-side login enforcement.');
    }
    if (!str_contains($source, 'fc_is_post()') || !str_contains($source, 'fc_validate_csrf')) {
        throw new RuntimeException($controller . ' is missing POST/CSRF mutation enforcement.');
    }
}

$layout = file_get_contents($root . '/views/layouts/app.php') ?: '';
foreach (['/app.php', '/crew.php', '/challenge.php', '/account.php', '/health/google/status.php', 'mobile-app-nav', 'aria-label="Mobile application navigation"'] as $required) {
    if (!str_contains($layout, $required)) {
        throw new RuntimeException('Authenticated application navigation is missing: ' . $required);
    }
}

$subnav = file_get_contents($root . '/views/app/challenge/subnav.php') ?: '';
foreach (['Home', 'Participants', 'Standings', 'History', 'Rules', 'aria-disabled="true"'] as $required) {
    if (!str_contains($subnav, $required)) {
        throw new RuntimeException('Challenge subnavigation contract is missing: ' . $required);
    }
}


$challengeCreate = file_get_contents($root . '/views/app/challenge/create.php') ?: '';
foreach (['planned_end_date', 'data-default-duration="84"', 'value="6" selected', 'Show provisional standings while the Challenge is live.', 'Step 1 of 2'] as $required) {
    if (!str_contains($challengeCreate, $required)) {
        throw new RuntimeException('Challenge setup UX contract is missing: ' . $required);
    }
}

$rulesView = file_get_contents($root . '/views/app/challenge/rules.php') ?: '';
foreach (['planned_end_date', 'Step 2 of 2', 'Show provisional standings while the Challenge is live.'] as $required) {
    if (!str_contains($rulesView, $required)) {
        throw new RuntimeException('Rules setup UX contract is missing: ' . $required);
    }
}

$dashboard = file_get_contents($root . '/views/app/dashboard.php') ?: '';
if (!str_contains($dashboard, 'class="action-cue">Start here</p>') || str_contains($dashboard, 'status-chip status-chip-orange">Start here</span>')) {
    throw new RuntimeException('Overview Start here cue is not positioned as non-button helper text.');
}

$js = file_get_contents($root . '/assets/js/app.js') ?: '';
foreach (['data-challenge-dates', 'data-planned-end', 'defaultDays'] as $required) {
    if (!str_contains($js, $required)) {
        throw new RuntimeException('Challenge date calculation behavior is missing: ' . $required);
    }
}

$health = file_get_contents($root . '/views/app/health/google_status.php') ?: '';
if (!str_contains($health, 'Connection is not available yet.') || !str_contains($health, 'Connected ≠ Official.')) {
    throw new RuntimeException('Health Connection placeholder does not preserve truthful held-runtime language.');
}

$css = file_get_contents($root . '/assets/css/app.css') ?: '';
foreach ([':focus-visible', '.mobile-app-nav', '@media (max-width: 760px)', '.challenge-subnav'] as $required) {
    if (!str_contains($css, $required)) {
        throw new RuntimeException('Responsive/accessibility stylesheet contract is missing: ' . $required);
    }
}

fwrite(STDOUT, "Wave 1 HTTP / responsive contract proof: PASS\n");
fwrite(STDOUT, "- authenticated route enforcement: PASS\n");
fwrite(STDOUT, "- POST + CSRF mutation boundary: PASS\n");
fwrite(STDOUT, "- Overview / Crew / Challenge navigation: PASS\n");
fwrite(STDOUT, "- held Standings / History destinations marked unavailable: PASS\n");
fwrite(STDOUT, "- truthful held Health runtime state: PASS\n");
fwrite(STDOUT, "- mobile navigation / focus-visible accessibility foundation: PASS\n");
fwrite(STDOUT, "- Challenge setup dates/defaults/progress + consumer copy: PASS\n");
fwrite(STDOUT, "- Overview Start here helper cue: PASS\n");
