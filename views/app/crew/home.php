<?php if ($crew === null): ?>
<p class="form-help">Signed in as <strong><?= fc_e((string) $currentUser['display_name']) ?></strong><?= $signedInEmail !== null ? ' (' . fc_e($signedInEmail) . ')' : ' — no verified email' ?>.</p>
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
            <button class="button button-primary button-small" type="button" data-modal-open="invite-crew-member-modal">Invite to Challenge</button>
        </div>
    <?php endif; ?>
</section>
<div class="member-grid">
    <?php foreach ($memberships as $member): ?>
        <?php $canRemoveMember = (string) $crew['membership_role'] === 'OWNER' && (string) $member['role_code'] !== 'OWNER' && (string) $member['membership_status'] === 'ACTIVE'; ?>
        <article class="member-card<?= (string) $member['membership_status'] !== 'ACTIVE' ? ' is-muted' : '' ?>">
            <div class="avatar-badge" aria-hidden="true"><?= fc_e(strtoupper(substr(trim((string) ($member['display_name'] ?: 'F')), 0, 1))) ?></div>
            <?php $memberContactEmail = $memberContactEmails[(string) $member['user_public_id']] ?? ((int) $member['user_id'] === (int) $currentUser['user_id'] ? $signedInEmail : null); ?>
            <div class="member-card-copy"><strong><?= fc_e((string) ($member['display_name'] ?: 'FitCrew member')) ?></strong><?php if ($memberContactEmail !== null): ?><span class="member-contact-email"><?= fc_e((string) $memberContactEmail) ?></span><?php endif; ?><span><?= fc_e(ucfirst(strtolower((string) $member['role_code']))) ?> · <?= fc_e(ucfirst(strtolower((string) $member['membership_status']))) ?></span></div>
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
            <div><p class="eyebrow">Challenge Invitation</p><h2 id="invite-crew-member-title"><?php if ($currentChallenge !== null): ?>Invite someone to <?= fc_e((string)$currentChallenge['display_name']) ?>.<?php else: ?>No current Challenge yet.<?php endif; ?></h2><p><?php if ($currentChallenge !== null): ?>We’ll email a private Challenge review. Accepting joins this Crew and that Challenge in one journey.<?php else: ?>Create and publish the next Challenge before inviting participants.<?php endif; ?></p></div>
            <button class="fc-modal-close" type="button" data-modal-close aria-label="Close invitation"><span aria-hidden="true">×</span></button>
        </header>
        <div class="fc-modal-body">
            <form class="product-form" method="post" action="/crew.php">
                <?= fc_csrf_input() ?>
                <input type="hidden" name="action" value="invite_member">
                <?php if ($currentChallenge !== null): ?>
                <label>Email address<input type="email" name="email" maxlength="254" required autocomplete="email" placeholder="family@example.com"></label>
                <p class="form-help">The invitation itself verifies access to this email. Health connection comes later. If the recipient already uses FitCrew under another email, they can explicitly choose that account.</p>
                <div class="fc-modal-actions"><button class="button button-secondary" type="button" data-modal-close>Cancel</button><button class="button button-primary" type="submit">Send Challenge Invitation</button></div>
                <?php else: ?>
                <div class="fc-notice fc-notice-info"><strong>Create the next Challenge first.</strong><span>A Challenge invitation always identifies one specific current Challenge.</span></div>
                <div class="fc-modal-actions"><button class="button button-secondary" type="button" data-modal-close>Close</button></div>
                <?php endif; ?>
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
            <?php
            $transportStatus = (string)($invitation['transport_status'] ?? 'PENDING_SEND');
            $transportLabel = match ($transportStatus) {
                'TRANSPORT_ACCEPTED' => 'Email accepted by transport',
                'TRANSPORT_FAILED' => 'Email send failed',
                default => 'Email pending send',
            };
            ?>
            <div class="member-card-copy">
                <strong><?= fc_e((string)$invitation['invited_email']) ?></strong>
                <?php if ($invitation['challenge_id'] === null): ?>
                    <span>Legacy Crew-only invitation · <?= fc_e($transportLabel) ?> · expires <?= fc_e(date('M j, Y', strtotime((string)$invitation['expires_at']))) ?></span>
                    <small>This older invitation cannot accept the current Challenge. Cancel it and send a new Challenge invitation.</small>
                <?php else: ?>
                    <span><?= fc_e((string)$invitation['challenge_name']) ?> · Pending · <?= fc_e($transportLabel) ?> · expires <?= fc_e(date('M j, Y', strtotime((string)$invitation['expires_at']))) ?></span>
                <?php endif; ?>
            </div>
            <div class="section-bar-actions">
                <?php if ($invitation['challenge_id'] !== null): ?><form method="post" action="/crew.php"><?= fc_csrf_input() ?><input type="hidden" name="action" value="resend_invitation"><input type="hidden" name="invitation_public_id" value="<?= fc_e((string)$invitation['public_id']) ?>"><button class="button button-secondary button-small" type="submit">Resend</button></form><?php endif; ?>
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
