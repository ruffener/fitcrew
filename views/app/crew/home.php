<?php if ($appContext['crews'] === []): ?>
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

<?php elseif ($showCrewList): ?>
<section class="product-hero compact-product-hero">
    <div>
        <p class="eyebrow fc-type-meta">Crew</p>
        <h1 class="fc-type-page-title">Your Crews.</h1>
        <p class="fc-type-body">Own more than one Crew, belong to others, and keep each Crew’s current Challenge independent.</p>
    </div>
    <div class="hero-stat"><strong><?= count($appContext['crews']) ?></strong><span><?= count($appContext['crews']) === 1 ? 'Crew' : 'Crews' ?></span></div>
</section>

<section class="section-bar section-bar-spaced">
    <div>
        <p class="card-kicker">My Crews</p>
        <h2>Choose a home base.</h2>
    </div>
</section>

<div class="crew-index-grid">
    <?php foreach ($appContext['crews'] as $crewCandidate): ?>
        <?php
        $crewCurrent = $crewListCurrentChallenges[(int) $crewCandidate['id']] ?? null;
        $crewFormId = 'open-crew-' . (string) $crewCandidate['public_id'];
        $isOwner = (string) $crewCandidate['membership_role'] === 'OWNER';
        ?>
        <article
            class="product-card fc-clickable-card crew-index-card"
            role="button"
            tabindex="0"
            data-submit-form="<?= fc_e($crewFormId) ?>"
            aria-label="Open <?= fc_e((string) $crewCandidate['display_name']) ?>"
        >
            <div class="crew-index-card-head">
                <span class="status-chip <?= $isOwner ? 'status-chip-orange' : 'status-chip-neutral' ?>"><?= $isOwner ? 'Owner' : 'Crew Member' ?></span>
                <span class="fc-card-open-cue" aria-hidden="true">Open <span>→</span></span>
            </div>
            <div>
                <h2 class="fc-type-card-title"><?= fc_e((string) $crewCandidate['display_name']) ?></h2>
                <p class="fc-type-supporting"><?= !empty($crewCandidate['description']) ? fc_e((string) $crewCandidate['description']) : 'Private FitCrew community.' ?></p>
            </div>
            <div class="crew-index-facts">
                <span><strong><?= fc_e((string) $crewCandidate['member_count']) ?></strong> <?= (int) $crewCandidate['member_count'] === 1 ? 'member' : 'members' ?></span>
                <?php if ($crewCurrent !== null): ?>
                    <span><strong>Current Challenge</strong> <?= fc_e((string) $crewCurrent['display_name']) ?></span>
                <?php else: ?>
                    <span><strong>No current Challenge</strong><?= $isOwner ? ' · Ready for a new Challenge' : '' ?></span>
                <?php endif; ?>
            </div>
            <form id="<?= fc_e($crewFormId) ?>" method="post" action="/crew.php" hidden>
                <?= fc_csrf_input() ?>
                <input type="hidden" name="select_crew" value="<?= fc_e((string) $crewCandidate['public_id']) ?>">
            </form>
        </article>
    <?php endforeach; ?>
</div>

<section class="product-card family-section crew-create-card">
    <div class="section-bar">
        <div>
            <p class="card-kicker">Create New Crew</p>
            <h2>Start another private group.</h2>
            <p class="fc-type-supporting">Prelaunch does not enforce a Crew-ownership cap. Each Crew may have its own current Challenge.</p>
        </div>
    </div>
    <form class="product-form crew-create-inline" method="post" action="/crew.php">
        <?= fc_csrf_input() ?>
        <input type="hidden" name="action" value="create_crew">
        <label>Crew name<input type="text" name="display_name" maxlength="120" required placeholder="Work Crew"></label>
        <label>Short description <span>Optional</span><textarea name="description" maxlength="500" rows="3" placeholder="Coworkers competing together."></textarea></label>
        <button class="button button-primary" type="submit">Create New Crew</button>
    </form>
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

<div class="family-detail-controls">
    <a class="button button-secondary button-small" href="/crew.php">All Crews</a>
    <?php if ($currentChallenge !== null): ?>
        <a class="button button-secondary button-small" href="/challenge.php?view=detail&amp;challenge=<?= fc_e(rawurlencode((string) $currentChallenge['public_id'])) ?>">Open Current Challenge</a>
        <?php if ((string) $crew['membership_role'] === 'OWNER'): ?>
            <a class="button button-secondary button-small" href="/challenge-manage.php?challenge=<?= fc_e(rawurlencode((string) $currentChallenge['public_id'])) ?>">Manage Challenge</a>
        <?php endif; ?>
    <?php elseif ((string) $crew['membership_role'] === 'OWNER'): ?>
        <form method="post" action="/challenge.php">
            <?= fc_csrf_input() ?>
            <input type="hidden" name="action" value="prepare_new_challenge">
            <input type="hidden" name="crew_public_id" value="<?= fc_e((string) $crew['public_id']) ?>">
            <button class="button button-primary button-small" type="submit">Create Challenge</button>
        </form>
    <?php endif; ?>
</div>

<?php if (count($appContext['crews']) > 1): ?>
<section class="context-switcher" aria-label="Switch Crew">
    <span>Quick switch</span>
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
    <div class="inline-empty-state">
        <strong>No Challenge yet.</strong>
        <span><?= (string) $crew['membership_role'] === 'OWNER' ? 'Create the first competition when your Crew is ready.' : 'Your Crew Owner has not created a Challenge yet.' ?></span>
        <?php if ((string) $crew['membership_role'] === 'OWNER'): ?>
            <form method="post" action="/challenge.php">
                <?= fc_csrf_input() ?>
                <input type="hidden" name="action" value="prepare_new_challenge">
                <input type="hidden" name="crew_public_id" value="<?= fc_e((string) $crew['public_id']) ?>">
                <button class="button button-primary button-small" type="submit">Create Challenge</button>
            </form>
        <?php else: ?>
            <a class="button button-secondary button-small" href="/challenge.php">Open Challenge List</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="challenge-list">
        <?php foreach ($crewChallenges as $crewChallenge): ?>
            <?php
            $hasAccess = fc_challenge_user_has_access($pdo, (int) $currentUser['user_id'], (int) $crewChallenge['id']);
            $isCurrentCrewChallenge = $currentChallenge !== null && (int) $crewChallenge['id'] === (int) $currentChallenge['id'];
            ?>
            <article class="challenge-row">
                <div>
                    <span class="status-chip <?= $isCurrentCrewChallenge ? 'status-chip-orange' : 'status-chip-neutral' ?>"><?= $isCurrentCrewChallenge ? 'Current' : fc_e(fc_challenge_lifecycle_label((string) $crewChallenge['lifecycle_status'], (string) $crewChallenge['operational_state'])) ?></span>
                    <h3><?= fc_e((string) $crewChallenge['display_name']) ?></h3>
                    <p><?= fc_e((string) $crewChallenge['participant_count']) ?> active participants</p>
                </div>
                <div class="challenge-row-actions">
                    <?php if ($hasAccess): ?>
                        <a class="button button-secondary button-small" href="/challenge.php?view=detail&amp;challenge=<?= fc_e(rawurlencode((string) $crewChallenge['public_id'])) ?>">Open Challenge</a>
                    <?php else: ?>
                        <form method="post" action="/challenge.php"><?= fc_csrf_input() ?><input type="hidden" name="action" value="join_challenge"><input type="hidden" name="challenge_public_id" value="<?= fc_e((string) $crewChallenge['public_id']) ?>"><button class="button button-primary button-small" type="submit">Review and Join</button></form>
                    <?php endif; ?>
                    <?php if ((string) $crew['membership_role'] === 'OWNER' && (int) $crewChallenge['owner_user_id'] === (int) $currentUser['user_id']): ?>
                        <a class="button button-secondary button-small" href="/challenge-manage.php?challenge=<?= fc_e(rawurlencode((string) $crewChallenge['public_id'])) ?>">Manage</a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ((string) $crew['membership_role'] === 'OWNER' && $currentChallenge === null): ?>
        <div class="challenge-index-create-action">
            <form method="post" action="/challenge.php">
                <?= fc_csrf_input() ?>
                <input type="hidden" name="action" value="prepare_new_challenge">
                <input type="hidden" name="crew_public_id" value="<?= fc_e((string) $crew['public_id']) ?>">
                <button class="button button-primary" type="submit">Create New Challenge</button>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php endif; ?>
