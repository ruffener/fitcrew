<section class="product-hero compact-product-hero">
    <div>
        <p class="eyebrow">Challenge Owner</p>
        <h1><?= fc_e((string) $challenge['display_name']) ?></h1>
        <p><a class="hero-context-link" href="/crew.php?crew=<?= fc_e(rawurlencode((string) $challenge['crew_public_id'])) ?>"><?= fc_e((string) $challenge['crew_name']) ?></a> · Manage the current plan without losing Challenge history.</p>
    </div>
    <span class="status-chip <?= $isCurrentCrewChallenge ? 'status-chip-orange' : 'status-chip-neutral' ?>"><?= fc_e(fc_challenge_management_label($management)) ?></span>
</section>

<div class="family-detail-controls">
    <a href="/challenge.php?view=detail&amp;challenge=<?= fc_e(rawurlencode($publicId)) ?>" class="button button-secondary button-small">Open Challenge</a>
    <a href="/crew.php?crew=<?= fc_e(rawurlencode((string) $challenge['crew_public_id'])) ?>" class="button button-secondary button-small">Crew Home</a>
    <a href="/challenge.php?show=history" class="button button-secondary button-small">All Challenges &amp; history</a>
</div>

<section class="manage-summary-grid" aria-label="Challenge summary">
    <article class="manage-summary-card">
        <span>Status</span>
        <strong><?= fc_e(fc_challenge_management_label($management)) ?></strong>
        <small><?= $isCurrentCrewChallenge ? 'This is the Crew’s current / non-terminal Challenge.' : 'This Challenge is no longer the Crew’s current Challenge.' ?></small>
    </article>
    <article class="manage-summary-card">
        <span>Rules</span>
        <strong><?= $displayRule !== null ? 'Version ' . fc_e((string) $displayRule['version_number']) : 'Not configured' ?></strong>
        <small><?= $displayRule !== null ? fc_e(ucfirst(strtolower((string) $displayRule['version_status']))) : 'Create the Challenge Rules before launch.' ?></small>
    </article>
    <article class="manage-summary-card">
        <span>Schedule</span>
        <strong><?= $displayRule !== null && !empty($displayRule['planned_start_date']) ? fc_e((string) $displayRule['planned_start_date']) : 'Start date not set' ?></strong>
        <small><?= $displayRule !== null ? fc_e(fc_rule_duration_summary((int) $displayRule['duration_days'])) : 'Schedule lives in Rules.' ?></small>
    </article>
</section>

<div class="split-card-grid manage-primary-grid">
    <section class="product-card family-section">
        <p class="card-kicker">Basics</p>
        <h2>Challenge name</h2>
        <p class="fc-type-supporting">Change the display name without changing participation, Rules or history.</p>
        <form method="post" action="/challenge-manage.php" class="product-form">
            <?= fc_csrf_input() ?>
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="challenge_public_id" value="<?= fc_e($publicId) ?>">
            <input type="hidden" name="management_revision" value="<?= fc_e((string)$management['revision']) ?>">
            <label>Challenge name<input name="display_name" maxlength="140" required value="<?= fc_e((string)$challenge['display_name']) ?>"></label>
            <button type="submit" class="button button-primary">Save Name</button>
        </form>
    </section>

    <section class="product-card family-section">
        <p class="card-kicker">Rules &amp; Schedule</p>
        <h2>Change the plan, keep the history.</h2>
        <p class="fc-type-supporting">Dates, duration, check-in day, timezone and supported standings settings live in Rules. Published versions remain intact.</p>
        <?php if ($displayRule !== null): ?>
            <div class="manage-rule-facts">
                <span><strong>Start</strong><?= !empty($displayRule['planned_start_date']) ? fc_e((string) $displayRule['planned_start_date']) : 'Not set' ?></span>
                <span><strong>Duration</strong><?= fc_e(fc_rule_duration_summary((int) $displayRule['duration_days'])) ?></span>
                <span><strong>Timezone</strong><?= fc_e((string) $displayRule['challenge_timezone']) ?></span>
            </div>
        <?php endif; ?>
        <form method="post" action="/rules.php">
            <?= fc_csrf_input() ?>
            <input type="hidden" name="action" value="open_challenge_rules">
            <input type="hidden" name="challenge_public_id" value="<?= fc_e($publicId) ?>">
            <button class="button button-secondary" type="submit">Open Challenge Rules</button>
        </form>
    </section>
</div>

<?php
$actions=[
    'end'=>['title'=>'End Challenge','question'=>'End this Challenge now?','copy'=>'FitCrew will record that you ended this Challenge now. Participation and history stay intact. This action does not create final scores or choose a winner.','button'=>'End Challenge now','danger'=>true],
    'archive'=>['title'=>'Archive Challenge','question'=>'Move this Challenge to the archive?','copy'=>'This hides the Challenge from normal active lists. It does not end the Challenge or erase participation, Rules or results. You can bring it out of the archive later.','button'=>'Archive Challenge','danger'=>false],
    'unarchive'=>['title'=>'Unarchive Challenge','question'=>'Bring this Challenge out of the archive?','copy'=>'Only its archive setting will change. An earlier end or deletion record is not undone.','button'=>'Unarchive Challenge','danger'=>false],
    'delete'=>['title'=>'Delete Challenge','question'=>'Delete this Challenge?','copy'=>'Remove this Challenge from active lists. Rules, participation and required history are kept, and participants keep access to their personal choices. This does not delete accounts, erase health data or disconnect a health provider.','button'=>'Delete Challenge','danger'=>true],
];
?>

<section class="product-card family-section">
    <p class="card-kicker">Lifecycle &amp; Visibility</p>
    <h2>Owner controls</h2>
    <p class="fc-type-supporting">These controls change how the Challenge is used or displayed. They preserve participation and historical truth.</p>
    <div class="family-control-grid">
        <?php foreach (['end','archive','unarchive'] as $action): ?>
            <?php $spec = $actions[$action]; ?>
            <?php if (($action==='end' && $management['effective_end_at']!==null)||($action==='archive' && $management['archived_at']!==null)||($action==='unarchive' && $management['archived_at']===null)) continue; ?>
            <button class="button <?= $spec['danger'] ? 'button-danger-ghost' : 'button-secondary' ?>" type="button" data-modal-open="manage-<?= fc_e($action) ?>-modal"><?= fc_e($spec['title']) ?></button>
        <?php endforeach; ?>
    </div>
    <?php if ($management['effective_end_at']!==null): ?><p class="form-help">Effective end: <?= fc_e((string)$management['effective_end_at']) ?> UTC. Final competitive result: not determined by this action.</p><?php endif; ?>
</section>

<?php if ($management['deleted_at'] === null): ?>
<section class="product-card family-section manage-danger-zone">
    <p class="card-kicker">Danger Zone</p>
    <h2>Remove from active use</h2>
    <p class="fc-type-supporting">Delete removes the Challenge from ordinary active use while preserving required participation, Rules and history.</p>
    <button class="button button-danger-ghost" type="button" data-modal-open="manage-delete-modal">Delete Challenge</button>
</section>
<?php endif; ?>

<?php foreach ($actions as $action=>$spec): ?>
<dialog class="fc-modal<?= $spec['danger'] ? ' fc-modal-danger' : '' ?>" id="manage-<?= fc_e($action) ?>-modal" data-fitcrew-modal aria-labelledby="manage-<?= fc_e($action) ?>-title"><div class="fc-modal-panel"><header class="fc-modal-hero<?= $spec['danger'] ? ' fc-modal-hero-danger' : '' ?>"><div><p class="eyebrow"><?= fc_e($spec['title']) ?></p><h2 id="manage-<?= fc_e($action) ?>-title"><?= fc_e($spec['question']) ?></h2><p><?= fc_e((string)$challenge['display_name']) ?></p></div><button class="fc-modal-close" type="button" data-modal-close aria-label="Close confirmation">×</button></header><div class="fc-modal-body"><p><?= fc_e($spec['copy']) ?></p><form method="post" action="/challenge-manage.php" class="product-form"><?= fc_csrf_input() ?><input type="hidden" name="action" value="<?= fc_e($action) ?>"><input type="hidden" name="confirm_action" value="<?= fc_e($action) ?>"><input type="hidden" name="challenge_public_id" value="<?= fc_e($publicId) ?>"><input type="hidden" name="management_revision" value="<?= fc_e((string)$management['revision']) ?>"><?php if ($action==='end'): ?><label>Reason <span>Optional</span><textarea name="reason" maxlength="500" rows="3"></textarea></label><?php endif; ?><div class="fc-modal-actions"><button class="button button-secondary" type="button" data-modal-close>Cancel</button><button class="button <?= $spec['danger'] ? 'button-danger' : 'button-primary' ?>" type="submit"><?= fc_e($spec['button']) ?></button></div></form></div></div></dialog>
<?php endforeach; ?>

<?php if ($ownerHistory!==[]): ?>
<section class="product-card family-section">
    <p class="card-kicker">Owner action history</p>
    <h2>What changed</h2>
    <ul class="family-history-list"><?php foreach ($ownerHistory as $event): ?><li><strong><?= fc_e(ucwords(strtolower(str_replace('_',' ',(string)$event['event_code'])))) ?></strong><span><?= fc_e((string)$event['actor_name']) ?> · <?= fc_e((string)$event['occurred_at']) ?> UTC</span></li><?php endforeach; ?></ul>
    <p class="form-help">Individual privacy choices and private health data are not shown in this Owner history.</p>
</section>
<?php endif; ?>
