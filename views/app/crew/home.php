<?php if ($crew === null): ?>
<p class="form-help">Signed in as <strong><?= fc_e((string) $currentUser['display_name']) ?></strong><?= $signedInEmail !== null ? ' (' . fc_e($signedInEmail) . ')' : ' — no verified email' ?>.</p>
<section class="product-hero">
    <div>
        <p class="eyebrow fc-type-meta">Crew</p>
        <h1 class="fc-type-page-title">Your people. Your home base.</h1>
        <p class="fc-type-body">A Crew is a private FitCrew community. Create one now; Challenges can be added when your Crew is ready.</p>
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
        <p>Your Crew is private. Invitations are accepted personally, and joining the Crew does not automatically enter every Challenge.</p>
    </article>
</section>
<?php else: ?>
<section class="product-hero">
    <div>
        <p class="eyebrow fc-type-meta">Crew</p>
        <h1 class="fc-type-page-title"><?= fc_e((string) $crew['display_name']) ?></h1>
        <p class="fc-type-body"><?= !empty($crew['description']) ? fc_e((string) $crew['description']) : 'Your private FitCrew community.' ?></p>
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

<section class="product-card crew-people-card" aria-labelledby="crew-people-title">
    <div class="crew-people-card-copy">
        <p class="card-kicker fc-type-meta">People</p>
        <h2 class="fc-type-section-title" id="crew-people-title">One Crew roster. Clear Challenge status.</h2>
        <p class="fc-type-body">Crew membership is durable. Participation in <?= $currentChallenge !== null ? fc_e((string) $currentChallenge['display_name']) : 'the next Challenge' ?> remains explicit and Challenge-specific.</p>
        <?php if ($currentChallenge !== null): ?><p class="fc-type-supporting"><strong>Current Challenge:</strong> <?= fc_e((string) $currentChallenge['display_name']) ?></p><?php else: ?><p class="fc-type-supporting">There is no current Challenge for this Crew.</p><?php endif; ?>
    </div>
    <a class="button button-primary" href="/participants.php?crew=<?= fc_e(rawurlencode((string) $crew['public_id'])) ?>">Open People</a>
</section>

<section class="section-bar section-bar-spaced">
    <div><p class="card-kicker">Challenges</p><h2>Compete together.</h2></div>
    <a class="button button-secondary button-small" href="/challenge.php">All Challenges</a>
</section>
<?php if ($crewChallenges === []): ?>
    <div class="inline-empty-state"><strong>No Challenge yet.</strong><span><?= (string) $crew['membership_role'] === 'OWNER' ? 'Use the Challenge list to create the first competition when your Crew is ready.' : 'Your Crew Owner has not created a Challenge yet.' ?></span><a class="button button-secondary button-small" href="/challenge.php">Open Challenge List</a></div>
<?php else: ?>
    <div class="challenge-list">
        <?php foreach ($crewChallenges as $crewChallenge): ?>
            <?php $hasAccess = fc_challenge_user_has_access($pdo, (int) $currentUser['user_id'], (int) $crewChallenge['id']); ?>
            <article class="challenge-row">
                <div><span class="status-chip status-chip-neutral"><?= fc_e(fc_challenge_lifecycle_label((string) $crewChallenge['lifecycle_status'], (string) $crewChallenge['operational_state'])) ?></span><h3><?= fc_e((string) $crewChallenge['display_name']) ?></h3><p><?= fc_e((string) $crewChallenge['participant_count']) ?> active participants</p></div>
                <div class="challenge-row-actions">
                    <?php if ($hasAccess): ?>
                        <form method="post" action="/challenge.php"><?= fc_csrf_input() ?><button class="button button-secondary button-small" type="submit" name="select_challenge" value="<?= fc_e((string) $crewChallenge['public_id']) ?>">Open Challenge</button></form>
                    <?php else: ?>
                        <form method="post" action="/challenge.php"><?= fc_csrf_input() ?><input type="hidden" name="action" value="join_challenge"><input type="hidden" name="challenge_public_id" value="<?= fc_e((string) $crewChallenge['public_id']) ?>"><button class="button button-primary button-small" type="submit">Review and Join</button></form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php endif; ?>
