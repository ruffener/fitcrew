<section class="product-hero compact-product-hero">
    <div><p class="eyebrow">My participation &amp; privacy</p><h1><?= fc_e((string)$challenge['display_name']) ?></h1><p><?= fc_e((string)$challenge['crew_name']) ?> · Your acceptance and sharing choices stay yours.</p></div>
</section>
<div class="section-bar family-section-bar"><a class="button button-secondary button-small" href="/challenge.php">All Challenges</a><span class="status-chip status-chip-neutral"><?= fc_e($participation ? ucfirst(strtolower((string)$participation['participation_status'])) : ($offer && $offer['offer_status'] === 'PENDING' ? 'Pending acceptance' : 'Not participating')) ?></span></div>
<?php if (fc_challenge_management_label($management) !== 'Current'): ?>
<div class="family-notice"><strong><?= fc_e(fc_challenge_management_label($management)) ?></strong><p>This Challenge is not in normal active use. You can still control your own privacy, review your participation history and withdraw. Joining does not reopen the Challenge or guarantee a competitive result.</p></div>
<?php endif; ?>
<?php if ($offer && $offer['offer_status'] === 'PENDING'): ?>
<div class="family-notice"><strong>You have been invited to participate.</strong><p>The invitation does not make you a participant. Only you can accept the Rules and choose what to share.</p>
<form method="post" action="/participation.php"><?= fc_csrf_input() ?><input type="hidden" name="challenge_public_id" value="<?= fc_e($publicId) ?>"><input type="hidden" name="action" value="decline"><input type="hidden" name="offer_public_id" value="<?= fc_e((string)$offer['public_id']) ?>"><button class="button button-secondary button-small" type="submit">Decline invitation</button></form></div>
<?php endif; ?>
<?php
$isActive = $participation !== null && $participation['participation_status'] === 'ACTIVE';
$offerAllowsAcceptance = $offer === null || in_array($offer['offer_status'], ['PENDING', 'ACCEPTED'], true);
$removedNeedsInvite = $participation !== null && $participation['participation_status'] === 'REMOVED' && ($offer === null || $offer['offer_status'] !== 'PENDING');
$needsAcceptance = !$isActive || $myAcceptance === null || ($currentRule !== null && (int)$myAcceptance['rule_version_id'] !== (int)$currentRule['id']);
?>
<?php if ($needsAcceptance && $personal['is_crew_member'] && $currentRule !== null && $offerAllowsAcceptance && !$removedNeedsInvite): ?>
<section class="product-card family-section">
    <p class="card-kicker">Review before accepting</p><h2>Challenge Rules · Version <?= fc_e((string)$currentRule['version_number']) ?></h2>
    <dl class="family-facts">
        <div><dt>Planned dates</dt><dd><?= fc_e((string)$currentRule['planned_start_date']) ?> — <?= fc_e((string)fc_rule_planned_end_date($currentRule['planned_start_date'],(int)$currentRule['duration_days'])) ?></dd></div>
        <div><dt>Duration</dt><dd><?= fc_e(fc_rule_duration_summary((int)$currentRule['duration_days'])) ?></dd></div>
        <div><dt>Weekly check-in</dt><dd><?= fc_e(fc_weekday_label((int)$currentRule['weekly_checkin_day'])) ?></dd></div>
        <div><dt>Timezone</dt><dd><?= fc_e((string)$currentRule['challenge_timezone']) ?></dd></div>
        <div><dt>Scoring standard</dt><dd><?= fc_e((string)$currentRule['scoring_standard_code']) ?></dd></div>
        <div><dt>Provisional standings</dt><dd><?= (int)$currentRule['live_leaderboard_visible'] === 1 ? 'Shown when available' : 'Not shown' ?></dd></div>
    </dl>
    <p class="form-help">You can join after the planned start. Your entry date is recorded; eligibility for competitive results is checked separately.</p>
    <?php if ($isActive && $myAcceptance === null): ?><p class="family-notice">Your participation is saved. Please review these Rules and confirm your personal choice.</p><?php endif; ?>
    <form method="post" action="/participation.php" class="product-form">
        <?= fc_csrf_input() ?><input type="hidden" name="action" value="accept"><input type="hidden" name="challenge_public_id" value="<?= fc_e($publicId) ?>"><input type="hidden" name="rule_version_id" value="<?= fc_e((string)$currentRule['id']) ?>">
        <?php if ($offer !== null): ?><input type="hidden" name="offer_public_id" value="<?= fc_e((string)$offer['public_id']) ?>"><?php endif; ?>
        <?php require fc_path('views/app/challenge/privacy_fields.php'); ?>
        <label class="family-consent"><input type="checkbox" name="accept_contract" value="yes" required><span>I accept these Challenge Rules and understand that my competition results are Challenge-visible while my underlying health sharing stays under my control. This does not authorize health-data collection.</span></label>
        <button class="button button-primary" type="submit"><?= $isActive ? 'Record my acceptance' : 'Accept and Join Challenge' ?></button>
    </form>
</section>
<?php elseif ($currentRule === null): ?>
<div class="inline-empty-state"><strong>Waiting for published Rules.</strong><span>The Owner can invite you now. Your personal acceptance waits until there is a published contract to review.</span></div>
<?php endif; ?>
<?php if ((!$offerAllowsAcceptance || $removedNeedsInvite) && !$isActive): ?><div class="family-notice"><strong>A new invitation is needed to join.</strong><p>Your earlier invitation or participation is no longer active. The Owner can invite you again at any stage. Your personal history and privacy choices remain available.</p></div><?php endif; ?>
<?php if ($myAcceptance !== null): ?>
<div class="family-notice"><strong>Your acceptance is recorded.</strong><p>Accepted at <?= fc_e((string)$myAcceptance['accepted_at']) ?> UTC. Later Owner changes do not rewrite this receipt.</p></div>
<?php endif; ?>
<?php if ($participation !== null || !$needsAcceptance || !$personal['is_crew_member'] || $currentRule === null || !$offerAllowsAcceptance || $removedNeedsInvite): ?>
<section class="product-card family-section"><h2>My privacy</h2><p>You can change these choices without accepting new Rules, rejoining, or asking the Owner.</p><form method="post" action="/participation.php" class="product-form"><?= fc_csrf_input() ?><input type="hidden" name="action" value="privacy"><input type="hidden" name="challenge_public_id" value="<?= fc_e($publicId) ?>"><?php require fc_path('views/app/challenge/privacy_fields.php'); ?><button class="button button-primary" type="submit">Save my sharing choices</button></form></section>
<?php endif; ?>
<?php if ($isActive): ?>
<section class="quiet-action family-section"><div><strong>You can step out at any time.</strong><span>Withdrawal does not leave your Crew or disconnect your account-level Health.</span></div><button type="button" class="button button-danger-ghost" data-modal-open="withdraw-challenge-modal">Withdraw from Challenge</button></section>
<dialog class="fc-modal fc-modal-danger" id="withdraw-challenge-modal" data-fitcrew-modal aria-labelledby="withdraw-challenge-title"><div class="fc-modal-panel"><header class="fc-modal-hero fc-modal-hero-danger"><div><p class="eyebrow">Your choice</p><h2 id="withdraw-challenge-title">Withdraw from this Challenge?</h2><p><?= fc_e((string)$challenge['display_name']) ?></p></div><button class="fc-modal-close" type="button" data-modal-close aria-label="Close withdrawal confirmation">×</button></header><div class="fc-modal-body"><p>Your participation interval will end now. Earlier participation, Rules and any governed results stay in history. Your Crew, account and Health connection are not deleted.</p><form method="post" action="/participation.php"><?= fc_csrf_input() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="confirm_action" value="withdraw"><input type="hidden" name="challenge_public_id" value="<?= fc_e($publicId) ?>"><div class="fc-modal-actions"><button class="button button-secondary" type="button" data-modal-close>Keep participating</button><button class="button button-danger" type="submit">Withdraw now</button></div></form></div></div></dialog>
<?php endif; ?>
<?php if ($myIntervals !== []): ?>
<section class="product-card family-section"><p class="card-kicker">Only your record</p><h2>My participation history</h2><ul class="family-history-list"><?php foreach ($myIntervals as $interval): ?><li><strong><?= fc_e((string)$interval['entered_at']) ?> UTC</strong><span><?= $interval['exit_status'] === null ? 'Participation remains open' : fc_e(ucfirst(strtolower((string)$interval['exit_status']))) . ' · ' . fc_e((string)($interval['exited_at'] ?? 'Time not recorded')) . ' UTC' ?></span><?php if ($interval['entry_source'] === 'LEGACY_SNAPSHOT'): ?><small>Preserved from your earlier participation record.</small><?php endif; ?></li><?php endforeach; ?></ul></section>
<?php endif; ?>
