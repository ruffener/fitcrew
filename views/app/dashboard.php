<?php
$crew = $appContext['crew'];
$challenge = $appContext['challenge'];
$firstName = trim((string) ($currentUser['display_name'] ?? ''));
$welcomeName = $firstName !== '' ? $firstName : 'there';
?>
<section class="product-hero product-hero-overview">
    <div>
        <p class="eyebrow">Overview</p>
        <h1>Good to see you, <?= fc_e($welcomeName) ?>.</h1>
        <?php if ($crew === null): ?>
            <p>FitCrew starts with your people. Create a private Crew, then build your first Challenge together.</p>
        <?php elseif ($challenge === null): ?>
            <p><strong><?= fc_e((string) $crew['display_name']) ?></strong> is ready. Your next step is to start or join a Challenge.</p>
        <?php else: ?>
            <p>You’re in <strong><?= fc_e((string) $crew['display_name']) ?></strong>. Here’s the current state of <strong><?= fc_e((string) $challenge['display_name']) ?></strong>.</p>
        <?php endif; ?>
    </div>
    <?php if ($challenge !== null): ?>
        <span class="status-chip status-chip-blue"><?= fc_e(fc_challenge_lifecycle_label((string) $challenge['lifecycle_status'], (string) $challenge['operational_state'])) ?></span>
    <?php elseif ($crew !== null): ?>
        <span class="status-chip status-chip-success">Crew ready</span>
    <?php else: ?>
        <span class="status-chip status-chip-orange">Start here</span>
    <?php endif; ?>
</section>

<?php if ($crew === null): ?>
<section class="empty-state-feature">
    <div class="empty-state-icon" aria-hidden="true">FC</div>
    <div>
        <p class="card-kicker">Your Crew</p>
        <h2>Bring your people together.</h2>
        <p>A Crew is your private home base. Challenges come and go; your Crew stays together.</p>
        <a class="button button-primary" href="/crew.php">Create your Crew</a>
    </div>
</section>
<?php elseif ($challenge === null): ?>
<section class="overview-grid overview-grid-two">
    <article class="product-card product-card-accent-blue">
        <p class="card-kicker">Current Crew</p>
        <h2><?= fc_e((string) $crew['display_name']) ?></h2>
        <p><?= (int) $crew['member_count'] === 1 ? '1 member' : fc_e((string) $crew['member_count']) . ' members' ?> · <?= fc_e((string) $crew['membership_role']) ?></p>
        <a class="text-link" href="/crew.php">View Crew →</a>
    </article>
    <article class="product-card product-card-accent-orange">
        <p class="card-kicker">Next step</p>
        <h2>No Current Challenge</h2>
        <p><?= (string) $crew['membership_role'] === 'OWNER' ? 'Create a Challenge and publish the rules your Crew will compete under.' : 'Your Crew Owner has not started a Challenge you participate in yet.' ?></p>
        <?php if ((string) $crew['membership_role'] === 'OWNER'): ?>
            <a class="button button-primary" href="/challenge.php">Create a Challenge</a>
        <?php else: ?>
            <a class="text-link" href="/crew.php">View Crew activity →</a>
        <?php endif; ?>
    </article>
</section>
<?php else: ?>
<?php
$participation = fc_challenge_participation_for_user($pdo, (int) $challenge['id'], (int) $currentUser['user_id']);
$currentRule = fc_challenge_rule_current_published($pdo, (int) $challenge['id']);
$isOwner = (int) $challenge['owner_user_id'] === (int) $currentUser['user_id'];
?>
<section class="status-action-card">
    <div class="status-action-marker" aria-hidden="true">✓</div>
    <div>
        <p class="card-kicker">Your status</p>
        <?php if ($participation !== null && (string) $participation['participation_status'] === 'ACTIVE'): ?>
            <h2>No action needed.</h2>
            <p>You’re an active participant. FitCrew will surface the next governed action here when authoritative Challenge truth is available.</p>
        <?php elseif ($isOwner): ?>
            <h2>You own this Challenge.</h2>
            <p>Owner authority and participation are separate. Join the Challenge if you also plan to compete.</p>
            <a class="button button-secondary" href="/participants.php">Review Participants</a>
        <?php else: ?>
            <h2>Challenge access is active.</h2>
            <p>Your current Challenge relationship is preserved. Review Participants for the current participation state.</p>
        <?php endif; ?>
    </div>
    <div class="status-action-meta">
        <span>Current lifecycle</span>
        <strong><?= fc_e(fc_challenge_lifecycle_label((string) $challenge['lifecycle_status'], (string) $challenge['operational_state'])) ?></strong>
    </div>
</section>

<section class="overview-grid overview-grid-three">
    <article class="metric-card metric-card-blue">
        <span class="metric-label">Challenge</span>
        <strong><?= fc_e((string) $challenge['display_name']) ?></strong>
        <span><?= fc_e((string) $crew['display_name']) ?></span>
    </article>
    <article class="metric-card metric-card-orange">
        <span class="metric-label">Published rules</span>
        <strong><?= $currentRule !== null ? 'Version ' . fc_e((string) $currentRule['version_number']) : 'Not published' ?></strong>
        <span><?= $currentRule !== null ? fc_e(FC_CERTIFIED_SCORING_STANDARD) : 'Owner review required' ?></span>
    </article>
    <article class="metric-card metric-card-navy">
        <span class="metric-label">Competition</span>
        <strong>Awaiting governed truth</strong>
        <span>No score, rank, health, or Monies data is fabricated.</span>
    </article>
</section>

<section class="product-card product-card-wide next-look-card">
    <div>
        <p class="card-kicker">What happens next</p>
        <h2>One Challenge. One clear place to look.</h2>
        <p>As governed Health, Scoring, and optional Challenge Monies outputs become available, this Overview will surface only the current status, one action, progress, next checkpoint, and competition truth you’re allowed to see.</p>
    </div>
    <a class="button button-primary" href="/challenge.php">Open Challenge</a>
</section>
<?php endif; ?>
