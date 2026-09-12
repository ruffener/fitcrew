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
        <p>Your Crew is private. Invitations are accepted personally, and joining the Crew does not automatically enter every Challenge.</p>
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
    <?php if ((string) $crew['membership_role'] === 'OWNER'): ?>
        <div class="section-bar-actions">
            <span class="status-chip status-chip-blue">Crew Owner</span>
            <button class="button button-primary button-small" type="button" data-modal-open="invite-crew-member-modal">Invite Member</button>
        </div>
    <?php endif; ?>
</section>
<div class="member-grid">
    <?php foreach ($memberships as $member): ?>
        <?php $canRemoveMember = (string) $crew['membership_role'] === 'OWNER' && (string) $member['role_code'] !== 'OWNER' && (string) $member['membership_status'] === 'ACTIVE'; ?>
        <article class="member-card<?= (string) $member['membership_status'] !== 'ACTIVE' ? ' is-muted' : '' ?>">
            <div class="avatar-badge" aria-hidden="true"><?= fc_e(strtoupper(substr(trim((string) ($member['display_name'] ?: 'F')), 0, 1))) ?></div>
            <div class="member-card-copy"><strong><?= fc_e((string) ($member['display_name'] ?: 'FitCrew member')) ?></strong><span><?= fc_e(ucfirst(strtolower((string) $member['role_code']))) ?> · <?= fc_e(ucfirst(strtolower((string) $member['membership_status']))) ?></span></div>
            <?php if ($canRemoveMember): ?>
                <button class="member-remove-button" type="button" data-modal-open="remove-member-<?= fc_e((string) $member['user_public_id']) ?>">Remove</button>
            <?php endif; ?>
        </article>

        <?php if ($canRemoveMember): ?>
            <dialog class="fc-modal fc-modal-danger" id="remove-member-<?= fc_e((string) $member['user_public_id']) ?>" data-fitcrew-modal aria-labelledby="remove-member-<?= fc_e((string) $member['user_public_id']) ?>-title">
                <div class="fc-modal-panel">
                    <header class="fc-modal-hero fc-modal-hero-danger">
                        <div>
                            <p class="eyebrow">Remove Crew Member</p>
                            <h2 id="remove-member-<?= fc_e((string) $member['user_public_id']) ?>-title">Remove <?= fc_e((string) ($member['display_name'] ?: 'this member')) ?>?</h2>
                            <p>This removes ordinary Crew and Challenge access for this Crew. Personal history and privacy rights remain available.</p>
                        </div>
                        <button class="fc-modal-close" type="button" data-modal-close aria-label="Close remove member confirmation"><span aria-hidden="true">×</span></button>
                    </header>
                    <div class="fc-modal-body">
                        <div class="danger-confirmation-copy">
                            <strong>History will be preserved.</strong>
                            <span>Past membership and Challenge participation records remain in FitCrew, but this member will no longer have access to this Crew.</span>
                        </div>
                        <div class="fc-modal-actions">
                            <button class="button button-secondary" type="button" data-modal-close>Keep Member</button>
                            <form method="post" action="/crew.php">
                                <?= fc_csrf_input() ?>
                                <input type="hidden" name="action" value="remove_member">
                                <input type="hidden" name="crew_public_id" value="<?= fc_e((string)$crew['public_id']) ?>">
                                <input type="hidden" name="confirm_action" value="remove_member">
                                <input type="hidden" name="member_public_id" value="<?= fc_e((string) $member['user_public_id']) ?>">
                                <button class="button button-danger" type="submit">Remove Crew Member</button>
                            </form>
                        </div>
                    </div>
                </div>
            </dialog>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<?php if ((string) $crew['membership_role'] === 'OWNER'): ?>
<dialog class="fc-modal" id="invite-crew-member-modal" data-fitcrew-modal aria-labelledby="invite-crew-member-title">
    <div class="fc-modal-panel">
        <header class="fc-modal-hero">
            <div><p class="eyebrow">Invite Member</p><h2 id="invite-crew-member-title">Invite someone to <?= fc_e((string)$crew['display_name']) ?>.</h2><p>We’ll email a private invitation. They become a member only after signing in and accepting.</p></div>
            <button class="fc-modal-close" type="button" data-modal-close aria-label="Close invitation"><span aria-hidden="true">×</span></button>
        </header>
        <div class="fc-modal-body">
            <form class="product-form" method="post" action="/crew.php">
                <?= fc_csrf_input() ?>
                <input type="hidden" name="action" value="invite_member">
                <label>Email address<input type="email" name="email" maxlength="254" required autocomplete="email" placeholder="family@example.com"></label>
                <p class="form-help">The email address is only the invitation destination. It does not become FitCrew identity.</p>
                <div class="fc-modal-actions"><button class="button button-secondary" type="button" data-modal-close>Cancel</button><button class="button button-primary" type="submit">Send Invitation</button></div>
            </form>
        </div>
    </div>
</dialog>

<?php if ($pendingInvitations !== []): ?>
<section class="section-bar section-bar-spaced"><div><p class="card-kicker">Pending invitations</p><h2>Waiting for acceptance.</h2></div></section>
<div class="member-grid">
    <?php foreach ($pendingInvitations as $invitation): ?>
        <article class="member-card is-muted">
            <div class="avatar-badge" aria-hidden="true">@</div>
            <div class="member-card-copy"><strong><?= fc_e((string)$invitation['invited_email']) ?></strong><span>Pending · expires <?= fc_e(date('M j, Y', strtotime((string)$invitation['expires_at']))) ?></span></div>
            <div class="section-bar-actions">
                <form method="post" action="/crew.php"><?= fc_csrf_input() ?><input type="hidden" name="action" value="resend_invitation"><input type="hidden" name="invitation_public_id" value="<?= fc_e((string)$invitation['public_id']) ?>"><button class="button button-secondary button-small" type="submit">Resend</button></form>
                <form method="post" action="/crew.php"><?= fc_csrf_input() ?><input type="hidden" name="action" value="cancel_invitation"><input type="hidden" name="invitation_public_id" value="<?= fc_e((string)$invitation['public_id']) ?>"><button class="button button-danger button-small" type="submit">Cancel</button></form>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

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
