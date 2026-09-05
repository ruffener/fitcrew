<?php if (!empty($showCreateChallenge)): ?>
    <?php require fc_path('views/app/challenge/create.php'); ?>
<?php elseif ($crew === null): ?>
<section class="product-hero">
    <div><p class="eyebrow">Challenge</p><h1>Start with a Crew.</h1><p>Create or join a Crew before entering a FitCrew Challenge.</p></div>
    <span class="status-chip status-chip-orange">Crew required</span>
</section>
<div class="inline-empty-state"><strong>No Crew selected.</strong><span>Your Challenge home will appear here once you have a Crew.</span><a class="button button-primary button-small" href="/crew.php">Go to Crew</a></div>
<?php elseif ($challenge === null): ?>
<section class="product-hero">
    <div><p class="eyebrow">Challenge</p><h1>No Current Challenge</h1><p><?= (string) $crew['membership_role'] === 'OWNER' ? 'Build the competition your Crew will take on together.' : 'You do not currently participate in a Challenge for this Crew.' ?></p></div>
    <span class="status-chip status-chip-neutral">No current Challenge</span>
</section>
<?php if ((string) $crew['membership_role'] === 'OWNER'): ?>
    <?php require fc_path('views/app/challenge/create.php'); ?>
<?php else: ?>
    <div class="inline-empty-state"><strong>No active Challenge participation.</strong><span>Open Crew to see any Challenge you are eligible to join.</span><a class="button button-secondary button-small" href="/crew.php">View Crew</a></div>
<?php endif; ?>
<?php else: ?>
<?php require fc_path('views/app/challenge/subnav.php'); ?>
<section class="product-hero challenge-hero">
    <div><p class="eyebrow"><?= fc_e((string) $crew['display_name']) ?></p><h1><?= fc_e((string) $challenge['display_name']) ?></h1><p>One governed competition, one current place to look.</p></div>
    <div class="lifecycle-badge"><span>Lifecycle</span><strong><?= fc_e(fc_challenge_lifecycle_label((string) $challenge['lifecycle_status'], (string) $challenge['operational_state'])) ?></strong></div>
</section>

<section class="status-action-card">
    <div class="status-action-marker" aria-hidden="true"><?= (string) $challenge['lifecycle_status'] === 'DRAFT' ? '1' : '✓' ?></div>
    <div>
        <p class="card-kicker">Current status</p>
        <?php if ((string) $challenge['lifecycle_status'] === 'DRAFT'): ?>
            <h2>Review the Challenge Rules.</h2><p>This Challenge is still a draft. Publish Rule Version 1 before the Crew moves into Forming Crew.</p>
            <?php if ((int) $challenge['owner_user_id'] === $userId): ?><a class="button button-primary button-small" href="/rules.php">Review Rules</a><?php else: ?><span class="status-chip status-chip-neutral">Waiting for Challenge Owner</span><?php endif; ?>
        <?php elseif ($participation === null || (string) $participation['participation_status'] !== 'ACTIVE'): ?>
            <?php if ((int) $challenge['owner_user_id'] === $userId): ?>
                <h2>Owner and participant are separate.</h2><p>You own this Challenge but are not currently an active participant.</p><form method="post" action="/challenge.php"><?= fc_csrf_input() ?><input type="hidden" name="action" value="join_challenge"><input type="hidden" name="challenge_public_id" value="<?= fc_e((string) $challenge['public_id']) ?>"><button class="button button-secondary button-small" type="submit">Join This Challenge</button></form>
            <?php else: ?>
                <h2>Participation is not active.</h2><p>Your Crew membership remains separate from Challenge participation.</p>
            <?php endif; ?>
        <?php else: ?>
            <h2>No action needed.</h2><p>You’re an active participant. The next governed action will appear here when an authorized downstream service supplies it.</p>
        <?php endif; ?>
    </div>
    <div class="status-action-meta"><span>Participation</span><strong><?= $participation !== null ? fc_e(ucfirst(strtolower((string) $participation['participation_status']))) : 'Not participating' ?></strong></div>
</section>

<section class="overview-grid overview-grid-three">
    <article class="metric-card metric-card-blue"><span class="metric-label">Rules</span><strong><?= $currentRule !== null ? 'Published v' . fc_e((string) $currentRule['version_number']) : 'Draft' ?></strong><span><?= fc_e(FC_CERTIFIED_SCORING_STANDARD) ?></span></article>
    <article class="metric-card metric-card-orange"><span class="metric-label">Participants</span><strong><?= fc_e((string) count(fc_challenge_participants($pdo, $userId, (int) $challenge['id']))) ?></strong><span>Explicit Challenge relationships</span></article>
    <article class="metric-card metric-card-navy"><span class="metric-label">Competition truth</span><strong>Not available yet</strong><span>No score, rank, health, or Stack value is invented.</span></article>
</section>

<section class="split-card-grid">
    <article class="product-card">
        <p class="card-kicker">Competition</p><h2>Official and Live results belong here.</h2><p>Standings remain unavailable until FitCrew receives authoritative Scoring output. Website will not calculate BODY_COMPOSITION_V1_0 independently.</p><span class="status-chip status-chip-neutral">Awaiting authoritative scoring</span>
    </article>
    <article class="product-card">
        <p class="card-kicker">Health readiness</p><h2>Connection is not readiness.</h2><p>Future Challenge readiness will distinguish connected, measurement received, eligible, Official, and scoring result. No provider runtime is implied here.</p><a class="text-link" href="/health/google/status.php">Health Connections →</a>
    </article>
</section>

<?php if ((int) $challenge['owner_user_id'] === $userId): ?>
<section class="quiet-action"><div><strong>Planning another Challenge?</strong><span>Create a new Draft without changing this Challenge or its history.</span></div><a class="button button-secondary button-small" href="/challenge.php?new=1">New Challenge</a></section>
<?php endif; ?>
<?php endif; ?>
