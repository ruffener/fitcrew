<section class="product-hero compact-product-hero">
    <div>
        <p class="eyebrow">Challenges</p>
        <h1>Your competitions.</h1>
        <p>Open any Challenge you own or participate in. Choosing a Challenge also selects its Crew.</p>
    </div>
</section>
<nav class="family-view-switch" aria-label="Challenge list view"><a class="button button-secondary button-small" href="/challenge.php">Current Challenges</a><a class="button button-secondary button-small" href="/challenge.php?show=history">Include ended, archived &amp; deleted</a></nav>

<?php if ($showCreateChallenge): ?>
    <section class="challenge-create-panel">
        <div class="section-bar">
            <div>
                <p class="card-kicker">New Challenge</p>
                <h2>Create for <?= fc_e((string) $crew['display_name']) ?>.</h2>
            </div>
            <a class="text-link" href="/challenge.php">Cancel</a>
        </div>
        <?php require fc_path('views/app/challenge/create.php'); ?>
    </section>
<?php endif; ?>

<?php
$visibleCrewGroups = [];
foreach ($appContext['crews'] as $crewCandidate) {
    $crewChallenges = array_values(array_filter(
        $allChallenges,
        static fn (array $candidate): bool => (int) $candidate['crew_id'] === (int) $crewCandidate['id']
    ));

    if ($crewChallenges !== [] || (string) $crewCandidate['membership_role'] === 'OWNER') {
        $visibleCrewGroups[] = ['crew' => $crewCandidate, 'challenges' => $crewChallenges];
    }
}
?>

<?php if ($visibleCrewGroups === []): ?>
    <section class="empty-state-feature">
        <div class="empty-state-icon" aria-hidden="true">FC</div>
        <div>
            <p class="card-kicker">No Challenges yet</p>
            <h2>Your Challenge list will live here.</h2>
            <p>Join a Challenge from one of your Crews, or create one when you are a Crew Owner.</p>
            <a class="button button-secondary" href="/crew.php">View Crews</a>
        </div>
    </section>
<?php else: ?>
    <div class="challenge-index-groups">
        <?php foreach ($visibleCrewGroups as $group): ?>
            <?php $groupCrew = $group['crew']; $groupChallenges = $group['challenges']; ?>
            <section class="challenge-index-group">
                <div class="section-bar">
                    <div>
                        <p class="card-kicker">Crew</p>
                        <h2><?= fc_e((string) $groupCrew['display_name']) ?></h2>
                    </div>
                </div>

                <?php if ($groupChallenges === []): ?>
                    <div class="inline-empty-state">
                        <strong>No Challenges in your list for this Crew.</strong>
                        <span><?= (string) $groupCrew['membership_role'] === 'OWNER' ? 'Create the first Challenge when this Crew is ready.' : 'Join a Challenge from Crew when one becomes available.' ?></span>
                    </div>
                <?php else: ?>
                    <div class="challenge-list challenge-index-list">
                        <?php foreach ($groupChallenges as $listedChallenge): ?>
                            <?php
                            $isOwner = (int) $listedChallenge['owner_user_id'] === $userId;
                            $participantStatus = (string) ($listedChallenge['participation_status'] ?? '');
                            $statusLabel = $participantStatus === 'ACTIVE'
                                ? 'Active participant'
                                : ($participantStatus === 'WITHDRAWN'
                                    ? 'Withdrawn'
                                    : ($isOwner ? 'Owner · Not competing' : 'Challenge access'));
                            ?>
                            <?php $selectFormId = 'select-challenge-' . (string) $listedChallenge['public_id']; ?>
                            <article
                                class="challenge-row challenge-index-row fc-clickable-card"
                                role="button"
                                tabindex="0"
                                data-submit-form="<?= fc_e($selectFormId) ?>"
                                aria-label="Open <?= fc_e((string) $listedChallenge['display_name']) ?>"
                            >
                                <div class="challenge-index-title">
                                    <span class="status-chip status-chip-neutral"><?= fc_e(fc_challenge_lifecycle_label((string) $listedChallenge['lifecycle_status'], (string) $listedChallenge['operational_state'])) ?></span>
                                    <h3><?= fc_e((string) $listedChallenge['display_name']) ?></h3>
                                    <?php if ($listedChallenge['effective_end_at'] !== null || $listedChallenge['archived_at'] !== null || $listedChallenge['deleted_at'] !== null): ?><p class="family-history-state"><?= fc_e(fc_challenge_management_label($listedChallenge)) ?></p><?php endif; ?>
                                    <p><?= fc_e($statusLabel) ?> · <?= fc_e((string) $listedChallenge['participant_count']) ?> active <?= (int) $listedChallenge['participant_count'] === 1 ? 'participant' : 'participants' ?></p>
                                </div>
                                <div class="challenge-row-actions">
                                    <?php if ($isOwner): ?><a class="button button-secondary button-small" href="/challenge-manage.php?challenge=<?= fc_e(rawurlencode((string)$listedChallenge['public_id'])) ?>">Manage Challenge</a><?php endif; ?>
                                    <span class="fc-card-open-cue" aria-hidden="true">Open <span>→</span></span>
                                </div>
                                <form id="<?= fc_e($selectFormId) ?>" method="post" action="/challenge.php" hidden>
                                    <?= fc_csrf_input() ?>
                                    <input type="hidden" name="select_challenge" value="<?= fc_e((string) $listedChallenge['public_id']) ?>">
                                </form>
                            </article>

                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ((string) $groupCrew['membership_role'] === 'OWNER'): ?>
                    <div class="challenge-index-create-action">
                        <form method="post" action="/challenge.php">
                            <?= fc_csrf_input() ?>
                            <input type="hidden" name="action" value="prepare_new_challenge">
                            <input type="hidden" name="crew_public_id" value="<?= fc_e((string) $groupCrew['public_id']) ?>">
                            <button class="button button-primary" type="submit">Create New Challenge</button>
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
$personalItems = array_values(array_filter($personalChallenges,static fn(array $p):bool=>
    $p['offer_status']==='PENDING' || ($p['participation_status']!==null && ($p['participation_status']!=='ACTIVE' || $p['effective_end_at']!==null || $p['archived_at']!==null || $p['deleted_at']!==null))
));
?>
<?php if ($personalItems!==[]): ?>
<section class="product-card family-section"><p class="card-kicker">Personal access</p><h2>Invitations &amp; my participation history</h2><p>Pending invitations do not give access to a Challenge roster. Your own history and privacy controls remain available after withdrawal, removal or deletion from active use.</p><div class="family-personal-list"><?php foreach ($personalItems as $item): ?><a class="fc-action-tile family-personal-item" href="/participation.php?challenge=<?= fc_e(rawurlencode((string)$item['public_id'])) ?>"><strong><?= fc_e((string)$item['display_name']) ?></strong><span><?= fc_e((string)$item['crew_name']) ?> · <?= $item['offer_status']==='PENDING' ? 'Pending personal acceptance' : 'My history &amp; privacy' ?></span><span class="fc-action-chevron" aria-hidden="true">→</span></a><?php endforeach; ?></div></section>
<?php endif; ?>
