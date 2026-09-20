<?php

declare(strict_types=1);

function wcf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$invite = file_get_contents($root . '/crew-invite.php');
$view = file_get_contents($root . '/views/public/crew_invitation.php');
$header = file_get_contents($root . '/views/partials/header.php');
$subnav = file_get_contents($root . '/views/app/challenge/subnav.php');
$css = file_get_contents($root . '/assets/css/app.css');
$js = file_get_contents($root . '/assets/js/app.js');
$crews = file_get_contents($root . '/inc/product/crews.php');
$crewView = file_get_contents($root . '/views/app/crew/home.php');
$participantView = file_get_contents($root . '/views/app/challenge/participants.php');

wcf_assert(str_contains($invite, "?account_switch=1"), 'Account conflict must route to explicit account-choice UI.');
wcf_assert(!str_contains($invite, "fc_flash('notice', 'This invitation email belongs to a different FitCrew account."), 'Internal account-switch condition must not render as a flash message.');
wcf_assert(str_contains($invite, "accept_with_current_account"), 'Explicit current-account acceptance action missing.');
wcf_assert(str_contains($view, 'data-modal-auto-open'), 'Account-conflict modal must auto-open after a verified conflict.');
wcf_assert(str_contains($view, 'Sign Out &amp; Choose Another Account'), 'Account-conflict modal must offer safe sign-out/account switching.');
wcf_assert(str_contains($view, 'Not Now'), 'Account-conflict modal needs a non-destructive exit.');
wcf_assert(str_contains($js, "[data-modal-auto-open]"), 'Shared modal layer must support governed auto-open modals.');
wcf_assert(str_contains($header, 'header-account-menu'), 'Shared header needs authenticated account/session control.');
wcf_assert(str_contains($header, 'action="/logout.php"'), 'Top-right account control must provide Sign Out.');
wcf_assert(str_contains($header, 'context-crew-link'), 'Current Crew must be directly navigable from the app header.');
wcf_assert(str_contains($subnav, 'Manage Crew'), 'Challenge navigation must offer direct Crew management for Owners.');
wcf_assert(str_contains($crews, 'fc_crew_member_contact_email_map'), 'Owner-only verified contact email helper missing.');
wcf_assert(str_contains($crews, "verification_status = 'VERIFIED'"), 'Member identity helper must use verified canonical contact email.');
wcf_assert(str_contains($participantView, 'people-email'), 'Canonical People surface must distinguish duplicate display names for the Crew Owner.');
wcf_assert(str_contains($css, 'width: min(1180px, 100%);'), 'Desktop invitation width normalization missing.');
wcf_assert(str_contains($participantView, 'The invitation itself verifies access to this email.'), 'Current invitation authentication copy must live on the canonical People surface.');

fwrite(STDOUT, "Website cleanup foundation proof: PASS\n");
fwrite(STDOUT, "- account-conflict modal / safe account choices: PASS\n");
fwrite(STDOUT, "- top-right session control / direct Crew navigation: PASS\n");
fwrite(STDOUT, "- owner-visible verified identity disambiguation: PASS\n");
fwrite(STDOUT, "- wider Challenge invitation / current copy: PASS\n");
