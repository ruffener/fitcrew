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
<?php
$currentLifecycle = strtoupper((string) $challenge['lifecycle_status']);
$currentLifecycleIndex = array_search($currentLifecycle, FC_CHALLENGE_LIFECYCLES, true);
$currentLifecycleIndex = $currentLifecycleIndex === false ? 0 : (int) $currentLifecycleIndex;
$lifecycleStageCount = count(FC_CHALLENGE_LIFECYCLES);
$lifecycleDescriptions = [
    'DRAFT' => 'Set the Challenge basics and review Rule Version 1.',
    'FORMING_CREW' => 'Bring the Crew together and confirm who is taking on this Challenge.',
    'BASELINE' => 'Establish qualified starting measurements for participating Crew members.',
    'READY_TO_LAUNCH' => 'Baseline requirements are satisfied and the Challenge is ready to begin.',
    'LIVE' => 'The Challenge is underway. Official progress appears as governed data becomes available.',
    'FINAL_WEEK_LIVE' => 'The final live week is in progress. Keep showing up and finish strong.',
    'RESULTS_UNDER_REVIEW' => 'The Challenge has ended and final results are being checked before they are locked.',
    'COMPLETED' => 'Final results are complete and this Challenge becomes part of the Crew’s history.',
];
?>
<?php require fc_path('views/app/challenge/subnav.php'); ?>
<section class="product-hero challenge-hero">
    <div><p class="eyebrow"><?= fc_e((string) $crew['display_name']) ?></p><h1><?= fc_e((string) $challenge['display_name']) ?></h1><p>One governed competition, one current place to look.</p></div>
    <button
        class="lifecycle-badge lifecycle-badge-button"
        type="button"
        data-modal-open="challenge-lifecycle-modal"
        aria-haspopup="dialog"
        aria-controls="challenge-lifecycle-modal"
    >
        <span>Lifecycle</span>
        <strong><?= fc_e(fc_challenge_lifecycle_label($currentLifecycle)) ?></strong>
        <?php if (strtoupper((string) $challenge['operational_state']) === 'NEEDS_ATTENTION'): ?>
            <em>Needs attention</em>
        <?php endif; ?>
        <small>View journey <span aria-hidden="true">→</span></small>
    </button>
</section>

<dialog
    class="fc-modal lifecycle-modal"
    id="challenge-lifecycle-modal"
    data-fitcrew-modal
    aria-labelledby="challenge-lifecycle-title"
>
    <div class="fc-modal-panel">
        <header class="fc-modal-hero">
            <div>
                <p class="eyebrow">Challenge journey</p>
                <h2 id="challenge-lifecycle-title">From setup to finish.</h2>
                <p>Stage <?= fc_e((string) ($currentLifecycleIndex + 1)) ?> of <?= fc_e((string) $lifecycleStageCount) ?> · <?= fc_e(fc_challenge_lifecycle_label($currentLifecycle)) ?></p>
            </div>
            <button class="fc-modal-close" type="button" data-modal-close aria-label="Close Challenge journey">
                <span aria-hidden="true">×</span>
            </button>
        </header>

        <div class="fc-modal-body">
            <?php if (strtoupper((string) $challenge['operational_state']) === 'NEEDS_ATTENTION'): ?>
                <div class="lifecycle-attention">
                    <strong>Needs attention</strong>
                    <span>Your Challenge is still in <?= fc_e(fc_challenge_lifecycle_label($currentLifecycle)) ?>. This alert does not replace the lifecycle stage.</span>
                </div>
            <?php endif; ?>

            <ol class="lifecycle-timeline">
                <?php foreach (FC_CHALLENGE_LIFECYCLES as $index => $stage): ?>
                    <?php
                    $stageState = $index < $currentLifecycleIndex
                        ? 'complete'
                        : ($index === $currentLifecycleIndex ? 'current' : 'upcoming');
                    $stageStateLabel = $stageState === 'complete'
                        ? 'Complete'
                        : ($stageState === 'current' ? 'Current' : 'Upcoming');
                    ?>
                    <li class="lifecycle-step is-<?= fc_e($stageState) ?>">
                        <div class="lifecycle-step-marker" aria-hidden="true">
                            <?= $stageState === 'complete' ? '✓' : fc_e((string) ($index + 1)) ?>
                        </div>
                        <div class="lifecycle-step-copy">
                            <div class="lifecycle-step-heading">
                                <strong><?= fc_e(fc_challenge_lifecycle_label($stage)) ?></strong>
                                <span><?= fc_e($stageStateLabel) ?></span>
                            </div>
                            <p><?= fc_e($lifecycleDescriptions[$stage] ?? '') ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>

            <div class="fc-modal-note">
                <strong>Lifecycle shows where the Challenge is.</strong>
                <span>“Needs Attention” is an operational alert, not a separate lifecycle stage.</span>
            </div>
        </div>
    </div>
</dialog>

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

<?php $participantCount = count(fc_challenge_participants($pdo, $userId, (int) $challenge['id'])); ?>
<section class="challenge-action-grid" aria-label="Challenge details">
    <a class="challenge-action-button fc-action-tile" href="/participants.php">
        <span>Participants</span>
        <strong><?= fc_e((string) $participantCount) ?></strong>
        <small>View Challenge roster</small>
        <span class="fc-action-chevron" aria-hidden="true">→</span>
    </a>
    <a class="challenge-action-button fc-action-tile" href="/rules.php">
        <span>Rules</span>
        <strong><?= $currentRule !== null ? 'Published v' . fc_e((string) $currentRule['version_number']) : 'Draft' ?></strong>
        <small>Review Challenge Rules</small>
        <span class="fc-action-chevron" aria-hidden="true">→</span>
    </a>
    <a class="challenge-action-button fc-action-tile" href="/health/google/status.php">
        <span>Health readiness</span>
        <strong>Not connected</strong>
        <small>View Health Connections</small>
        <span class="fc-action-chevron" aria-hidden="true">→</span>
    </a>
</section>

<?php endif; ?>
