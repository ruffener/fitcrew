<section class="product-hero compact-product-hero">
    <div>
        <p class="eyebrow">Challenges</p>
        <h1>Your competitions.</h1>
        <p>Open any Challenge you own or participate in. Choosing a Challenge also selects its Crew.</p>
    </div>
</section>

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
                            $canDeleteDraft = $isOwner
                                && (string) $listedChallenge['lifecycle_status'] === 'DRAFT'
                                && (int) $listedChallenge['participant_count'] === 0;
                            $deleteModalId = 'delete-challenge-' . (string) $listedChallenge['public_id'];
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
                                    <p><?= fc_e($statusLabel) ?> · <?= fc_e((string) $listedChallenge['participant_count']) ?> active <?= (int) $listedChallenge['participant_count'] === 1 ? 'participant' : 'participants' ?></p>
                                </div>
                                <div class="challenge-row-actions">
                                    <?php if ($canDeleteDraft): ?>
                                        <button class="button button-danger-ghost button-small" type="button" data-modal-open="<?= fc_e($deleteModalId) ?>">Delete Draft</button>
                                    <?php endif; ?>
                                    <span class="fc-card-open-cue" aria-hidden="true">Open <span>→</span></span>
                                </div>
                                <form id="<?= fc_e($selectFormId) ?>" method="post" action="/challenge.php" hidden>
                                    <?= fc_csrf_input() ?>
                                    <input type="hidden" name="select_challenge" value="<?= fc_e((string) $listedChallenge['public_id']) ?>">
                                </form>
                            </article>

                            <?php if ($canDeleteDraft): ?>
                                <dialog class="fc-modal fc-modal-danger" id="<?= fc_e($deleteModalId) ?>" data-fitcrew-modal aria-labelledby="<?= fc_e($deleteModalId) ?>-title">
                                    <div class="fc-modal-panel">
                                        <header class="fc-modal-hero fc-modal-hero-danger">
                                            <div>
                                                <p class="eyebrow">Delete Draft Challenge</p>
                                                <h2 id="<?= fc_e($deleteModalId) ?>-title">Delete <?= fc_e((string) $listedChallenge['display_name']) ?>?</h2>
                                                <p>This action cannot be undone.</p>
                                            </div>
                                            <button class="fc-modal-close" type="button" data-modal-close aria-label="Close delete Challenge confirmation"><span aria-hidden="true">×</span></button>
                                        </header>
                                        <div class="fc-modal-body">
                                            <div class="danger-confirmation-copy">
                                                <strong>This Draft has no participant history and no published Rules.</strong>
                                                <span>Deleting it permanently removes the Draft Challenge and its unpublished Rule draft.</span>
                                            </div>
                                            <div class="fc-modal-actions">
                                                <button class="button button-secondary" type="button" data-modal-close>Keep Challenge</button>
                                                <form method="post" action="/challenge.php">
                                                    <?= fc_csrf_input() ?>
                                                    <input type="hidden" name="action" value="delete_challenge_draft">
                                                    <input type="hidden" name="challenge_public_id" value="<?= fc_e((string) $listedChallenge['public_id']) ?>">
                                                    <button class="button button-danger" type="submit">Delete Draft Challenge</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </dialog>
                            <?php endif; ?>
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
