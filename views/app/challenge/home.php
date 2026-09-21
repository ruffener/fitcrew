<?php if (!empty($showCreateChallenge)): ?>
    <?php require fc_path('views/app/challenge/create.php'); ?>
<?php elseif ($crew === null): ?>
<section class="product-hero">
    <div><p class="eyebrow fc-type-meta">Challenge</p><h1 class="fc-type-page-title">Start with a Crew.</h1><p class="fc-type-body">Create or join a Crew before entering a FitCrew Challenge.</p></div>
    <span class="status-chip status-chip-orange">Crew required</span>
</section>
<div class="inline-empty-state"><strong>No Crew selected.</strong><span>Your Challenge home will appear here once you have a Crew.</span><a class="button button-primary button-small" href="/crew.php">Go to Crew</a></div>
<?php elseif ($challenge === null): ?>
<section class="product-hero">
    <div><p class="eyebrow fc-type-meta">Challenge</p><h1 class="fc-type-page-title">No Current Challenge</h1><p class="fc-type-body"><?= (string) $crew['membership_role'] === 'OWNER' ? 'Build the competition your Crew will take on together.' : 'You do not currently participate in a Challenge for this Crew.' ?></p></div>
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
    'DRAFT' => 'Set the Challenge details, dates, rules and settings.',
    'FORMING_CREW' => 'Invite people and confirm who is taking on the Challenge.',
    'LAUNCHED' => 'The Challenge has officially begun.',
    'BASELINE' => 'Establish each participant’s qualified starting baseline during the first week of the Challenge.',
    'LIVE' => 'The Crew is competing and progress is being tracked.',
    'FINAL_WEEK_LIVE' => 'Finish strong. This is the final competitive week.',
    'RESULTS_UNDER_REVIEW' => 'The Challenge has ended and final results are being checked.',
    'COMPLETED' => 'Results are final and this Challenge becomes part of the Crew’s history.',
];
$lifecycleDestinations = [
    'DRAFT' => '/challenge-manage.php?challenge=' . rawurlencode((string) $challenge['public_id']),
    'FORMING_CREW' => '/participants.php?challenge=' . rawurlencode((string) $challenge['public_id']),
];
$lifecycleStageInfo = [
    'LAUNCHED' => 'Launch marks the official start of the Challenge. No extra Owner action is created here merely to keep the Challenge in a launched holding state.',
    'BASELINE' => 'Baseline Week is the first week of the Challenge. Qualified starting baselines are established before governed competitive progress can be compared.',
    'LIVE' => 'During Competing, the Crew is actively taking on the Challenge. Progress and standings appear only as their governed product services become available.',
    'FINAL_WEEK_LIVE' => 'Final Week is the last competitive week. Existing Challenge rules remain in force; this Journey row does not change scoring or timing.',
    'RESULTS_UNDER_REVIEW' => 'Competition has ended. Final results are checked before the Challenge is finalized. Finalization controls are not created by this Journey row.',
    'COMPLETED' => 'The Challenge is final and remains part of Crew history. Historical Challenge truth is preserved.',
];
?>
<?php require fc_path('views/app/challenge/subnav.php'); ?>
<section class="product-hero challenge-hero">
    <div><p class="eyebrow fc-type-meta"><?= fc_e((string) $crew['display_name']) ?></p><h1 class="fc-type-page-title"><?= fc_e((string) $challenge['display_name']) ?></h1><p class="fc-type-body">Your competition. Your people. Your next step.</p></div>
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
                <p class="eyebrow">Stage <?= fc_e((string) ($currentLifecycleIndex + 1)) ?> of <?= fc_e((string) $lifecycleStageCount) ?> · <?= fc_e(fc_challenge_lifecycle_label($currentLifecycle)) ?></p>
                <h2 id="challenge-lifecycle-title">Challenge Journey</h2>
                <p>Explore each stage without changing the Challenge lifecycle.</p>
            </div>
            <button class="fc-modal-close" type="button" data-modal-close aria-label="Close Challenge journey">
                <span aria-hidden="true">×</span>
            </button>
        </header>

        <div class="fc-modal-body">
            <?php if (fc_challenge_management_label($management) !== 'Current'): ?><div class="family-notice"><strong><?= fc_e(fc_challenge_management_label($management)) ?></strong><p>The stages below explain the competitive lifecycle; they do not promise that this Challenge will advance or produce a final result.</p></div><?php endif; ?>
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
                    <li class="lifecycle-step-shell is-<?= fc_e($stageState) ?>">
                        <?php if (isset($lifecycleDestinations[$stage])): ?>
                            <a
                                class="lifecycle-step lifecycle-step-action"
                                href="<?= fc_e($lifecycleDestinations[$stage]) ?>"
                                aria-label="<?= fc_e(fc_challenge_lifecycle_label($stage) . ': ' . $stageStateLabel . '. ' . ($lifecycleDescriptions[$stage] ?? '')) ?>"
                            >
                                <span class="lifecycle-step-marker" aria-hidden="true"><?= $stageState === 'complete' ? '✓' : fc_e((string) ($index + 1)) ?></span>
                                <span class="lifecycle-step-copy">
                                    <span class="lifecycle-step-heading"><strong><?= fc_e(fc_challenge_lifecycle_label($stage)) ?></strong><span><?= fc_e($stageStateLabel) ?></span></span>
                                    <span class="lifecycle-step-description"><?= fc_e($lifecycleDescriptions[$stage] ?? '') ?></span>
                                </span>
                                <span class="lifecycle-step-chevron" aria-hidden="true">→</span>
                            </a>
                        <?php else: ?>
                            <details class="lifecycle-step-details">
                                <summary
                                    class="lifecycle-step lifecycle-step-action"
                                    aria-label="<?= fc_e(fc_challenge_lifecycle_label($stage) . ': ' . $stageStateLabel . '. ' . ($lifecycleDescriptions[$stage] ?? '') . ' Explore stage information.') ?>"
                                >
                                    <span class="lifecycle-step-marker" aria-hidden="true"><?= $stageState === 'complete' ? '✓' : fc_e((string) ($index + 1)) ?></span>
                                    <span class="lifecycle-step-copy">
                                        <span class="lifecycle-step-heading"><strong><?= fc_e(fc_challenge_lifecycle_label($stage)) ?></strong><span><?= fc_e($stageStateLabel) ?></span></span>
                                        <span class="lifecycle-step-description"><?= fc_e($lifecycleDescriptions[$stage] ?? '') ?></span>
                                    </span>
                                    <span class="lifecycle-step-chevron" aria-hidden="true">→</span>
                                </summary>
                                <div class="lifecycle-stage-information">
                                    <strong>About <?= fc_e(fc_challenge_lifecycle_label($stage)) ?></strong>
                                    <p><?= fc_e($lifecycleStageInfo[$stage] ?? $lifecycleDescriptions[$stage] ?? '') ?></p>
                                </div>
                            </details>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>

            <div class="fc-modal-note">
                <strong>Lifecycle and Owner controls are separate.</strong>
                <span>Ending, archiving or deleting a Challenge does not mark later competitive stages complete. “Needs Attention” is an alert, not another stage.</span>
            </div>
        </div>
    </div>
</dialog>

<section class="status-action-card">
    <div class="status-action-marker" aria-hidden="true"><?= (string) $challenge['lifecycle_status'] === 'DRAFT' ? '1' : '✓' ?></div>
    <div>
        <p class="card-kicker">Current status</p>
        <?php if (fc_challenge_management_label($management) !== 'Current'): ?>
            <h2><?= fc_e(fc_challenge_management_label($management)) ?>.</h2><p>Owner management has been recorded separately from the competitive lifecycle. It does not declare a final result or erase participant rights.</p>
        <?php elseif ((string) $challenge['lifecycle_status'] === 'DRAFT'): ?>
            <h2>Review the Challenge Rules.</h2><p>This Challenge is still a draft. Publish Rule Version 1 before the Crew moves into Forming Crew.</p>
            <?php if ((int) $challenge['owner_user_id'] === $userId): ?><a class="button button-primary button-small" href="/rules.php?challenge=<?= fc_e(rawurlencode((string)$challenge['public_id'])) ?>">Review Rules</a><?php else: ?><span class="status-chip status-chip-neutral">Waiting for Challenge Owner</span><?php endif; ?>
        <?php elseif ($participation === null || (string) $participation['participation_status'] !== 'ACTIVE'): ?>
            <?php if ((int) $challenge['owner_user_id'] === $userId): ?>
                <h2>Owner and participant are separate.</h2><p>You own this Challenge but are not currently an active participant.</p><form method="post" action="/challenge.php"><?= fc_csrf_input() ?><input type="hidden" name="action" value="join_challenge"><input type="hidden" name="challenge_public_id" value="<?= fc_e((string) $challenge['public_id']) ?>"><button class="button button-secondary button-small" type="submit">Join This Challenge</button></form>
            <?php else: ?>
                <h2>Participation is not active.</h2><p>Your Crew membership remains separate from Challenge participation.</p>
            <?php endif; ?>
        <?php elseif (!empty($personalAcceptanceNeeded)): ?>
            <h2>Review your participation choices.</h2><p>Your participation is saved. Please review the current Rules and confirm your personal acceptance. Your privacy choices remain yours to change separately.</p><a class="button button-primary button-small" href="/participation.php?challenge=<?= fc_e(rawurlencode((string)$challenge['public_id'])) ?>">Review my participation</a>
        <?php else: ?>
            <h2>No action needed.</h2><p>You are participating. Your next check-in or result will appear here when those features are available.</p>
        <?php endif; ?>
    </div>
    <div class="status-action-meta"><span>Participation</span><strong><?= $participation !== null ? fc_e(ucfirst(strtolower((string) $participation['participation_status']))) : 'Not participating' ?></strong></div>
</section>

<?php $participantCount = count(array_filter(fc_challenge_participants($pdo, $userId, (int) $challenge['id']),static fn(array $p):bool=>$p['participation_status']==='ACTIVE')); ?>
<section class="challenge-action-grid" aria-label="Challenge details">
    <a class="challenge-action-button fc-action-tile" href="/participants.php?challenge=<?= fc_e(rawurlencode((string)$challenge['public_id'])) ?>">
        <span>Participants</span>
        <strong><?= fc_e((string) $participantCount) ?></strong>
        <small>View Challenge roster</small>
        <span class="fc-action-chevron" aria-hidden="true">→</span>
    </a>
    <a class="challenge-action-button fc-action-tile" href="/rules.php?challenge=<?= fc_e(rawurlencode((string)$challenge['public_id'])) ?>">
        <span>Rules</span>
        <strong><?= $currentRule !== null ? 'Published v' . fc_e((string) $currentRule['version_number']) : 'Draft' ?></strong>
        <small>Review Challenge Rules</small>
        <span class="fc-action-chevron" aria-hidden="true">→</span>
    </a>
    <a class="challenge-action-button fc-action-tile" href="/health/google/status.php">
        <span>Health readiness</span>
        <strong>Not available yet</strong>
        <small>View Health Connections</small>
        <span class="fc-action-chevron" aria-hidden="true">→</span>
    </a>
</section>

<section class="family-detail-controls" aria-label="Personal and Owner controls">
    <a class="button button-secondary" href="/participation.php?challenge=<?= fc_e(rawurlencode((string)$challenge['public_id'])) ?>">My participation &amp; privacy</a>
    <?php if ((int)$challenge['owner_user_id']===$userId): ?><a class="button button-secondary" href="/challenge-manage.php?challenge=<?= fc_e(rawurlencode((string)$challenge['public_id'])) ?>">Manage Challenge</a><?php endif; ?>
</section>
<?php endif; ?>
