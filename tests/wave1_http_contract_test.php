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

if (!str_contains($subnav, '/challenge.php?view=detail')) {
    throw new RuntimeException('Challenge Home subnavigation must return to the selected Challenge detail, not the Challenge list.');
}

$challengeIndex = file_get_contents($root . '/views/app/challenge/index.php') ?: '';
foreach (['Your competitions.', 'Create Challenge', 'Open Challenge', 'Delete Draft', 'fc-modal-danger'] as $required) {
    if (!str_contains($challengeIndex, $required)) {
        throw new RuntimeException('Challenge list/management contract is missing: ' . $required);
    }
}

$crewView = file_get_contents($root . '/views/app/crew/home.php') ?: '';
foreach (['Add Member', 'FitCrew Member ID', 'Remove Crew Member', 'History will be preserved.', 'data-fitcrew-modal'] as $required) {
    if (!str_contains($crewView, $required)) {
        throw new RuntimeException('Crew member management/modal contract is missing: ' . $required);
    }
}

$accountView = file_get_contents($root . '/views/app/account.php') ?: '';
if (!str_contains($accountView, 'FitCrew Member ID')) {
    throw new RuntimeException('Account must expose the stable FitCrew Member ID for Alpha Crew membership management.');
}


$challengeCreate = file_get_contents($root . '/views/app/challenge/create.php') ?: '';
foreach (['planned_end_date', 'data-default-duration="84"', 'data-duration-weeks', 'data-finish-mode', 'value="6" selected', 'Show provisional standings while the Challenge is live.', 'Step 1 of 2', 'Continue to Rules'] as $required) {
    if (!str_contains($challengeCreate, $required)) {
        throw new RuntimeException('Challenge setup UX contract is missing: ' . $required);
    }
}

$rulesView = file_get_contents($root . '/views/app/challenge/rules.php') ?: '';
foreach (['planned_end_date', 'Step 2 of 2', 'Review the Rules.', 'Publish Challenge Rules', 'Adjust Challenge settings', 'Show provisional standings while the Challenge is live.'] as $required) {
    if (!str_contains($rulesView, $required)) {
        throw new RuntimeException('Rules setup UX contract is missing: ' . $required);
    }
}


$challengeController = file_get_contents($root . '/challenge.php') ?: '';
if (str_contains($challengeController, "fc_flash('success', 'Challenge context updated.')")) {
    throw new RuntimeException('Normal Challenge navigation still emits the retired context-updated success flash.');
}
if (!str_contains($challengeController, "fc_redirect('/rules.php');")) {
    throw new RuntimeException('New Challenge creation must continue directly to the focused Rules review.');
}
foreach (['prepare_new_challenge', 'delete_challenge_draft', '/challenge.php?view=detail', 'views/app/challenge/index.php'] as $required) {
    if (!str_contains($challengeController, $required)) {
        throw new RuntimeException('Challenge list/detail routing contract is missing: ' . $required);
    }
}

$dashboard = file_get_contents($root . '/views/app/dashboard.php') ?: '';
if (!str_contains($dashboard, 'class="action-cue">Start here</p>') || str_contains($dashboard, 'status-chip status-chip-orange">Start here</span>')) {
    throw new RuntimeException('Overview Start here cue is not positioned as non-button helper text.');
}
foreach (['Your Challenges', 'Choose your competition.', 'overview-challenge-card', 'Lifecycle', 'Your status', 'Rules', 'Open Challenge'] as $required) {
    if (!str_contains($dashboard, $required)) {
        throw new RuntimeException('Overview Challenge-first contract is missing: ' . $required);
    }
}
foreach (['No action needed.', 'Awaiting governed truth', 'overview-grid overview-grid-three', 'status-chip status-chip-blue', 'Current Challenge', 'is-current'] as $retired) {
    if (str_contains($dashboard, $retired)) {
        throw new RuntimeException('Overview still contains retired summary/status treatment: ' . $retired);
    }
}
if (str_contains($dashboard, '/challenge.php?new=1')) {
    throw new RuntimeException('Overview must select Challenges only; new Challenge creation belongs to the Challenge list.');
}
if (str_contains($crewView, '/challenge.php?new=1')) {
    throw new RuntimeException('Crew Home must not create Challenges directly; new Challenge creation belongs to the Challenge list.');
}

$challengeHome = file_get_contents($root . '/views/app/challenge/home.php') ?: '';
foreach (['challenge-lifecycle-modal', 'data-modal-open="challenge-lifecycle-modal"', 'Challenge journey', 'Stage <?=', 'Needs Attention', 'FC_CHALLENGE_LIFECYCLES', 'challenge-action-grid', 'Participants', 'Health readiness', 'Review Challenge Rules'] as $required) {
    if (!str_contains($challengeHome, $required)) {
        throw new RuntimeException('Challenge lifecycle/action contract is missing: ' . $required);
    }
}
foreach (['overview-grid overview-grid-three', 'Competition truth', 'Official and Live results belong here.', 'Planning another Challenge?'] as $retired) {
    if (str_contains($challengeHome, $retired)) {
        throw new RuntimeException('Challenge Home still contains retired dashboard clutter: ' . $retired);
    }
}

$contextSource = file_get_contents($root . '/inc/product/context.php') ?: '';
if (!str_contains($contextSource, 'fc_product_context_persist($pdo, $userId, $crewId, null)')) {
    throw new RuntimeException('Selecting a Crew must clear Challenge selection until the user explicitly opens a Challenge.');
}

$js = file_get_contents($root . '/assets/js/app.js') ?: '';
foreach (['data-challenge-dates', 'data-planned-end', 'data-duration-weeks', 'data-finish-mode', 'defaultDays', 'data-modal-open', 'data-fitcrew-modal', 'showModal'] as $required) {
    if (!str_contains($js, $required)) {
        throw new RuntimeException('Challenge date/modal behavior is missing: ' . $required);
    }
}

$health = file_get_contents($root . '/views/app/health/google_status.php') ?: '';
if (!str_contains($health, 'Connection is not available yet.') || !str_contains($health, 'Connected ≠ Official.')) {
    throw new RuntimeException('Health Connection placeholder does not preserve truthful held-runtime language.');
}

$css = file_get_contents($root . '/assets/css/app.css') ?: '';
foreach ([':focus-visible', '.mobile-app-nav', '@media (max-width: 760px)', '.challenge-subnav', '.fc-modal::backdrop', '.lifecycle-timeline', '.lifecycle-badge-button', '.challenge-action-grid', '.fc-modal-hero-danger', '.button-danger', '.member-remove-button'] as $required) {
    if (!str_contains($css, $required)) {
        throw new RuntimeException('Responsive/accessibility/modal stylesheet contract is missing: ' . $required);
    }
}

fwrite(STDOUT, "Wave 1 HTTP / responsive contract proof: PASS\n");
fwrite(STDOUT, "- authenticated route enforcement: PASS\n");
fwrite(STDOUT, "- POST + CSRF mutation boundary: PASS\n");
fwrite(STDOUT, "- Overview / Crew / Challenge navigation: PASS\n");
fwrite(STDOUT, "- held Standings / History destinations marked unavailable: PASS\n");
fwrite(STDOUT, "- truthful held Health runtime state: PASS\n");
fwrite(STDOUT, "- mobile navigation / focus-visible accessibility foundation: PASS\n");
fwrite(STDOUT, "- Challenge setup end-date/duration choice + Rules handoff: PASS\n");
fwrite(STDOUT, "- Overview Start here helper cue: PASS\n");
fwrite(STDOUT, "- Overview Challenge-first hierarchy + per-Challenge status: PASS\n");
fwrite(STDOUT, "- Crew selection + member add/remove modal contract: PASS\n");
fwrite(STDOUT, "- Challenge list/detail routing + Draft delete modal contract: PASS\n");
fwrite(STDOUT, "- Challenge Home focused action buttons: PASS\n");
fwrite(STDOUT, "- Challenge lifecycle journey + FitCrew modal foundation: PASS\n");
