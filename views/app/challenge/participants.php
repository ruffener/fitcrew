<?php require fc_path('views/app/challenge/subnav.php'); ?>
<section class="product-hero compact-product-hero"><div><p class="eyebrow">Participants</p><h1><?= fc_e((string) $challenge['display_name']) ?></h1><p>Challenge participation is explicit. Crew membership alone does not make someone a competitor.</p></div><span class="status-chip status-chip-blue"><?= fc_e((string) count(array_filter($participants, static fn(array $p): bool => (string) $p['participation_status'] === 'ACTIVE'))) ?> active</span></section>

<?php if ((int) $challenge['owner_user_id'] === $userId && ($participation === null || (string) $participation['participation_status'] !== 'ACTIVE') && in_array((string) $challenge['lifecycle_status'], ['DRAFT', 'FORMING_CREW'], true)): ?>
<section class="product-card participant-callout"><div><p class="card-kicker">Challenge Owner</p><h2>You are not currently competing.</h2><p>Owner authority and participant status are separate.</p></div><form method="post" action="/participants.php"><?= fc_csrf_input() ?><input type="hidden" name="action" value="join"><button class="button button-primary button-small" type="submit">Join Challenge</button></form></section>
<?php endif; ?>

<section class="section-bar"><div><p class="card-kicker">Challenge roster</p><h2>Participants</h2></div></section>
<?php if ($participants === []): ?>
<div class="inline-empty-state"><strong>No participants yet.</strong><span>Crew members must explicitly join this Challenge.</span></div>
<?php else: ?>
<div class="participant-list">
<?php foreach ($participants as $participant): ?>
    <article class="participant-row<?= (string) $participant['participation_status'] !== 'ACTIVE' ? ' is-muted' : '' ?>">
        <div class="avatar-badge" aria-hidden="true"><?= fc_e(strtoupper(substr(trim((string) ($participant['display_name'] ?: 'F')), 0, 1))) ?></div>
        <div class="participant-main"><strong><?= fc_e((string) ($participant['display_name'] ?: 'FitCrew member')) ?></strong><span><?= fc_e(ucfirst(strtolower((string) $participant['entry_kind']))) ?> entry</span></div>
        <span class="status-chip <?= (string) $participant['participation_status'] === 'ACTIVE' ? 'status-chip-success' : 'status-chip-neutral' ?>"><?= fc_e(ucfirst(strtolower((string) $participant['participation_status']))) ?></span>
    </article>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($participation !== null && (string) $participation['participation_status'] === 'ACTIVE' && in_array((string) $challenge['lifecycle_status'], ['DRAFT', 'FORMING_CREW'], true)): ?>
<section class="quiet-action"><div><strong>Need to step out before launch?</strong><span>Your participation record will remain in Challenge history.</span></div><form method="post" action="/participants.php"><?= fc_csrf_input() ?><input type="hidden" name="action" value="withdraw"><button class="button button-secondary button-small" type="submit">Withdraw Participation</button></form></section>
<?php endif; ?>
