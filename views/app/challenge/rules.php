<?php require fc_path('views/app/challenge/subnav.php'); ?>
<section class="product-hero compact-product-hero"><div><p class="eyebrow">Rules</p><h1>Challenge Contract</h1><p>Published Challenge truth is versioned. Published history is never silently overwritten.</p></div><?php if ($currentRule !== null): ?><span class="status-chip status-chip-success">Published v<?= fc_e((string) $currentRule['version_number']) ?></span><?php else: ?><span class="status-chip status-chip-orange">Draft only</span><?php endif; ?></section>

<?php if ($currentRule !== null): ?>
<section class="product-card rule-summary-card">
    <div class="section-bar"><div><p class="card-kicker">Current Published Rules</p><h2>Rule Version <?= fc_e((string) $currentRule['version_number']) ?></h2></div><span>Effective <?= fc_e((string) ($currentRule['published_at'] ?? '')) ?></span></div>
    <dl class="rule-facts">
        <div><dt>Certified scoring standard</dt><dd><?= fc_e((string) $currentRule['scoring_standard_code']) ?></dd></div>
        <div><dt>Planned start</dt><dd><?= fc_e((string) $currentRule['planned_start_date']) ?></dd></div>
        <div><dt>Planned end</dt><dd><?= fc_e((string) (fc_rule_planned_end_date((string) $currentRule['planned_start_date'], (int) $currentRule['duration_days']) ?? '')) ?></dd></div>
        <div><dt>Length</dt><dd><?= fc_e(fc_rule_duration_summary((int) $currentRule['duration_days'])) ?></dd></div>
        <div><dt>Weekly check-in</dt><dd><?= fc_e(fc_weekday_label((int) $currentRule['weekly_checkin_day'])) ?></dd></div>
        <div><dt>Challenge timezone</dt><dd><?= fc_e((string) $currentRule['challenge_timezone']) ?></dd></div>
        <div><dt>Live standings</dt><dd><?= (int) $currentRule['live_leaderboard_visible'] === 1 ? 'Show provisional standings when available' : 'Hidden' ?></dd></div>
    </dl>
    <div class="scoring-explainer"><strong>How scoring will work</strong><p>Your FitCrew score shows the percentage improvement in estimated fat mass compared with a frozen starting baseline. Official scores use multiple eligible days to reduce the effect of any single reading. Website will consume the certified scoring output; it does not calculate competitive truth here.</p><p><strong>Estimated body composition:</strong> Body-composition readings are estimates from consumer scales and can vary with hydration, meals, exercise, time of day, and other conditions. FitCrew uses them to track trends in a recreational competition, not as medical or clinical measurements.</p></div>
    <?php if ($isOwner && $draftRule === null): ?><form method="post" action="/rules.php" class="rule-update-action"><?= fc_csrf_input() ?><input type="hidden" name="action" value="begin_update"><button class="button button-secondary button-small" type="submit">Prepare Rule Update</button></form><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($draftRule !== null && $isOwner): ?>
<section class="product-card product-card-accent-orange">
    <div class="section-bar setup-section-bar">
        <div><p class="card-kicker">Draft Rule Version <?= fc_e((string) $draftRule['version_number']) ?></p><h2><?= $currentRule === null ? 'Review and publish the Challenge Rules.' : 'Prepare the next Rule Version.' ?></h2></div>
        <?php if ($currentRule === null): ?>
        <div class="setup-progress" aria-label="Challenge setup, step 2 of 2">
            <span>Challenge setup</span>
            <strong>Step 2 of 2</strong>
            <span class="setup-progress-track" aria-hidden="true"><i style="width:100%"></i></span>
        </div>
        <?php endif; ?>
    </div>
    <p>Draft changes are editable. Publishing creates the current Rules while preserving earlier published history.</p>
    <?php $draftEndDate = fc_rule_planned_end_date($draftRule['planned_start_date'] !== null ? (string) $draftRule['planned_start_date'] : null, (int) $draftRule['duration_days']); ?>
    <form class="product-form product-form-grid" method="post" action="/rules.php" data-challenge-dates data-default-duration="84">
        <?= fc_csrf_input() ?><input type="hidden" name="action" value="save_draft"><input type="hidden" name="rule_id" value="<?= fc_e((string) $draftRule['id']) ?>"><input type="hidden" name="duration_days" value="<?= fc_e((string) $draftRule['duration_days']) ?>" data-duration-days>
        <label>Planned start<input type="date" name="planned_start_date" value="<?= fc_e((string) ($draftRule['planned_start_date'] ?? '')) ?>" data-planned-start></label>
        <label>Planned end<input type="date" name="planned_end_date" value="<?= fc_e((string) ($draftEndDate ?? '')) ?>" data-planned-end><span data-duration-summary><?= fc_e(fc_rule_duration_summary((int) $draftRule['duration_days'])) ?></span></label>
        <label>Weekly check-in<select name="weekly_checkin_day"><?php for ($day=0;$day<=6;$day++): ?><option value="<?= $day ?>"<?= (int) $draftRule['weekly_checkin_day'] === $day ? ' selected' : '' ?>><?= fc_e(fc_weekday_label($day)) ?></option><?php endfor; ?></select></label>
        <label>Challenge timezone<select name="challenge_timezone"><?php foreach (['America/New_York'=>'Eastern Time','America/Chicago'=>'Central Time','America/Denver'=>'Mountain Time','America/Phoenix'=>'Arizona','America/Los_Angeles'=>'Pacific Time','America/Anchorage'=>'Alaska','Pacific/Honolulu'=>'Hawaii'] as $tz=>$label): ?><option value="<?= fc_e($tz) ?>"<?= (string) $draftRule['challenge_timezone'] === $tz ? ' selected' : '' ?>><?= fc_e($label) ?></option><?php endforeach; ?></select></label>
        <label class="checkbox-field form-span-2"><input type="checkbox" name="live_leaderboard_visible" value="1"<?= (int) $draftRule['live_leaderboard_visible'] === 1 ? ' checked' : '' ?>><span><strong>Show provisional standings while the Challenge is live.</strong><small>They appear only when official scoring data is available.</small></span></label>
        <div class="locked-standard form-span-2"><span>Certified scoring standard</span><strong><?= fc_e((string) $draftRule['scoring_standard_code']) ?></strong><small>Governance-certified scoring mathematics are not editable here.</small></div>
        <div class="form-span-2 form-actions"><button class="button button-secondary" type="submit">Save Draft</button></div>
    </form>
    <form method="post" action="/rules.php" class="publish-form"><?= fc_csrf_input() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="rule_id" value="<?= fc_e((string) $draftRule['id']) ?>"><button class="button button-primary" type="submit">Publish Rule Version <?= fc_e((string) $draftRule['version_number']) ?></button></form>
</section>
<?php elseif ($currentRule === null): ?>
<div class="inline-empty-state"><strong>No Published Rules yet.</strong><span>The Challenge Owner must publish the initial rule version before the Challenge moves forward.</span></div>
<?php endif; ?>

<section class="section-bar section-bar-spaced"><div><p class="card-kicker">Rule History</p><h2>Immutable versions</h2></div></section>
<div class="rule-history-list">
<?php foreach ($ruleHistory as $rule): ?>
    <article><div><strong>Version <?= fc_e((string) $rule['version_number']) ?></strong><span><?= fc_e(ucfirst(strtolower((string) $rule['version_status']))) ?></span></div><span><?= fc_e((string) ($rule['published_at'] ?: $rule['created_at'])) ?></span></article>
<?php endforeach; ?>
</div>
