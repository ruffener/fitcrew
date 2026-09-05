<?php if ($crew === null): ?>
<section class="product-hero">
    <div>
        <p class="eyebrow">Crew</p>
        <h1>Your people. Your home base.</h1>
        <p>A Crew is a private FitCrew community. Create one now; Challenges can be added when your Crew is ready.</p>
    </div>
    <span class="status-chip status-chip-orange">No Crew yet</span>
</section>
<section class="split-card-grid">
    <article class="product-card product-card-accent-blue">
        <p class="card-kicker">Create a Crew</p>
        <h2>Give your group a name.</h2>
        <form class="product-form" method="post" action="/crew.php">
            <?= fc_csrf_input() ?>
            <input type="hidden" name="action" value="create_crew">
            <label>Crew name<input type="text" name="display_name" maxlength="120" required placeholder="Weekend Warriors"></label>
            <label>Short description <span>Optional</span><textarea name="description" maxlength="500" rows="4" placeholder="Friends pushing each other to improve."></textarea></label>
            <button class="button button-primary" type="submit">Create Crew</button>
        </form>
    </article>
    <article class="product-card product-card-dark">
        <p class="card-kicker">Private by design</p>
        <h2>Crew access is membership-based.</h2>
        <p>Knowing a Crew identifier does not grant access. Membership and Owner authority are enforced on the server from the beginning.</p>
    </article>
</section>
<?php else: ?>
<section class="product-hero">
    <div>
        <p class="eyebrow">Crew</p>
        <h1><?= fc_e((string) $crew['display_name']) ?></h1>
        <p><?= !empty($crew['description']) ? fc_e((string) $crew['description']) : 'Your private FitCrew community.' ?></p>
    </div>
    <div class="hero-stat"><strong><?= fc_e((string) $crew['member_count']) ?></strong><span><?= (int) $crew['member_count'] === 1 ? 'member' : 'members' ?></span></div>
</section>

<?php if (count($appContext['crews']) > 1): ?>
<section class="context-switcher" aria-label="Switch Crew">
    <span>Switch Crew</span>
    <?php foreach ($appContext['crews'] as $candidate): ?>
        <form method="post" action="/crew.php">
            <?= fc_csrf_input() ?>
            <button type="submit" name="select_crew" value="<?= fc_e((string) $candidate['public_id']) ?>" class="context-choice<?= (int) $candidate['id'] === (int) $crew['id'] ? ' is-current' : '' ?>"><?= fc_e((string) $candidate['display_name']) ?></button>
        </form>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="section-bar">
    <div><p class="card-kicker">Crew members</p><h2>Your Crew</h2></div>
    <?php if ((string) $crew['membership_role'] === 'OWNER'): ?><span class="status-chip status-chip-blue">Crew Owner</span><?php endif; ?>
</section>
<div class="member-grid">
    <?php foreach ($memberships as $member): ?>
        <article class="member-card<?= (string) $member['membership_status'] !== 'ACTIVE' ? ' is-muted' : '' ?>">
            <div class="avatar-badge" aria-hidden="true"><?= fc_e(strtoupper(substr(trim((string) ($member['display_name'] ?: 'F')), 0, 1))) ?></div>
            <div><strong><?= fc_e((string) ($member['display_name'] ?: 'FitCrew member')) ?></strong><span><?= fc_e(ucfirst(strtolower((string) $member['role_code']))) ?> · <?= fc_e(ucfirst(strtolower((string) $member['membership_status']))) ?></span></div>
        </article>
    <?php endforeach; ?>
</div>

<section class="section-bar section-bar-spaced">
    <div><p class="card-kicker">Challenges</p><h2>Compete together.</h2></div>
    <?php if ((string) $crew['membership_role'] === 'OWNER'): ?><a class="button button-primary button-small" href="/challenge.php?new=1">Create Challenge</a><?php endif; ?>
</section>
<?php if ($crewChallenges === []): ?>
    <div class="inline-empty-state"><strong>No Challenge yet.</strong><span><?= (string) $crew['membership_role'] === 'OWNER' ? 'Create the first Challenge when your Crew is ready.' : 'Your Crew Owner has not created a Challenge yet.' ?></span></div>
<?php else: ?>
    <div class="challenge-list">
        <?php foreach ($crewChallenges as $crewChallenge): ?>
            <?php $hasAccess = fc_challenge_user_has_access($pdo, (int) $currentUser['user_id'], (int) $crewChallenge['id']); ?>
            <article class="challenge-row">
                <div><span class="status-chip status-chip-neutral"><?= fc_e(fc_challenge_lifecycle_label((string) $crewChallenge['lifecycle_status'], (string) $crewChallenge['operational_state'])) ?></span><h3><?= fc_e((string) $crewChallenge['display_name']) ?></h3><p><?= fc_e((string) $crewChallenge['participant_count']) ?> active participants</p></div>
                <div class="challenge-row-actions">
                    <?php if ($hasAccess): ?>
                        <form method="post" action="/challenge.php"><?= fc_csrf_input() ?><button class="button button-secondary button-small" type="submit" name="select_challenge" value="<?= fc_e((string) $crewChallenge['public_id']) ?>">Open Challenge</button></form>
                    <?php elseif (in_array((string) $crewChallenge['lifecycle_status'], ['DRAFT', 'FORMING_CREW'], true)): ?>
                        <form method="post" action="/challenge.php"><?= fc_csrf_input() ?><input type="hidden" name="action" value="join_challenge"><input type="hidden" name="challenge_public_id" value="<?= fc_e((string) $crewChallenge['public_id']) ?>"><button class="button button-primary button-small" type="submit">Join Challenge</button></form>
                    <?php else: ?>
                        <span class="status-chip status-chip-neutral">Not participating</span>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php endif; ?>
