<section class="public-section public-section-compact challenge-invite-page">
    <div class="fc-ui-card challenge-invite-card">
        <div class="challenge-invite-heading">
            <p class="fc-ui-eyebrow">Challenge Invitation</p>
            <?php if ($review === null): ?>
                <h1 class="fc-ui-title">Open your latest Challenge invitation.</h1>
                <p class="fc-ui-body">Use the private invitation link from the latest FitCrew email. Opening the link does not join a Crew, enter a Challenge, sign you in, or authorize health data.</p>
            <?php else: ?>
                <h1 class="fc-ui-title">You’re invited to <?= fc_e((string) $review['challenge_name']) ?>.</h1>
                <p class="fc-ui-body"><strong><?= fc_e((string) $review['inviter_name']) ?></strong> invited you to this Challenge with <strong><?= fc_e((string) $review['crew_name']) ?></strong>. Review the Challenge before you decide.</p>
            <?php endif; ?>
        </div>

        <?php if ($review !== null): ?>
            <div class="challenge-review-grid" aria-label="Challenge details">
                <section class="fc-ui-card fc-ui-card-soft challenge-review-section">
                    <p class="fc-ui-eyebrow">The Challenge</p>
                    <h2 class="fc-ui-section-title"><?= fc_e((string) $review['challenge_name']) ?></h2>
                    <dl class="challenge-review-facts">
                        <div><dt>Crew</dt><dd><?= fc_e((string) $review['crew_name']) ?></dd></div>
                        <div><dt>Invited by</dt><dd><?= fc_e((string) $review['inviter_name']) ?></dd></div>
                        <div><dt>Dates</dt><dd><?= fc_e((string) $review['planned_start_date']) ?> — <?= fc_e((string) fc_rule_planned_end_date((string) $review['planned_start_date'], (int) $review['duration_days'])) ?></dd></div>
                        <div><dt>Duration</dt><dd><?= fc_e(fc_rule_duration_summary((int) $review['duration_days'])) ?></dd></div>
                        <div><dt>Weekly check-in</dt><dd><?= fc_e(fc_weekday_label((int) $review['weekly_checkin_day'])) ?></dd></div>
                        <div><dt>Timezone</dt><dd><?= fc_e((string) $review['challenge_timezone']) ?></dd></div>
                    </dl>
                </section>

                <section class="fc-ui-card fc-ui-card-soft challenge-review-section">
                    <p class="fc-ui-eyebrow">Rules &amp; scoring</p>
                    <h2 class="fc-ui-section-title">Published Rules · Version <?= fc_e((string) $review['version_number']) ?></h2>
                    <p class="fc-ui-body">Official Body Composition scoring uses the governed <strong><?= fc_e((string) $review['scoring_standard_code']) ?></strong> standard. Provisional standings are <?= (int) $review['live_leaderboard_visible'] === 1 ? 'shown when authoritative scoring is available' : 'not shown' ?>.</p>
                    <div class="fc-notice fc-notice-info">
                        <strong>Health comes later.</strong>
                        <span>Accepting this Challenge does not connect Google Health, Apple Health, Health Connect, or any other provider. After you join, FitCrew will guide you through the health/readiness steps that apply to you.</span>
                    </div>
                </section>
            </div>

            <?php if ($signedInUser !== null): ?>
                <?php
                $displayName = trim((string) ($signedInUser['display_name'] ?? 'FitCrew member')) ?: 'FitCrew member';
                $verifiedEmail = $signedInEmail ?? 'No verified contact email on this account';
                ?>
                <div class="fc-notice fc-notice-account">
                    <strong>Signed in as <?= fc_e($displayName) ?></strong>
                    <span><?= fc_e($verifiedEmail) ?></span>
                </div>
                <?php if ($accountSwitchReady): ?>
                    <form method="post" action="/auth/invitation/switch-account.php" class="challenge-account-switch">
                        <?= fc_csrf_input() ?>
                        <button class="button button-secondary" type="submit">Use a different account</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" action="/crew-invite.php" class="product-form challenge-accept-form">
                <?= fc_csrf_input() ?>
                <input type="hidden" name="action" value="accept_challenge">

                <fieldset class="fc-ui-fieldset">
                    <legend>Privacy choices</legend>
                    <p class="fc-ui-help">Your official Challenge result and rank may be Challenge-visible. The health data that produces those results remains private unless you choose an approved sharing option.</p>
                    <label>Official Measurements
                        <select name="measurements_visibility">
                            <option value="PRIVATE" selected>Private — only me</option>
                            <option value="CHALLENGE">Share approved Official Measurements with this Challenge</option>
                        </select>
                    </label>
                    <label>Personal Progress
                        <select name="progress_visibility">
                            <option value="PRIVATE" selected>Private — only me</option>
                            <option value="CHALLENGE">Share approved Personal Progress with this Challenge</option>
                        </select>
                    </label>
                    <div class="fc-notice fc-notice-quiet"><strong>Raw Health / Provider Data stays private.</strong><span>Raw provider records and payloads are never ordinary Challenge-visible data.</span></div>
                </fieldset>

                <label class="family-consent challenge-consent">
                    <input type="checkbox" name="accept_contract" value="yes" required>
                    <span>I accept these Challenge Rules and understand that accepting joins the Crew and this Challenge with the account I use. This does not authorize health-data collection.</span>
                </label>

                <div class="challenge-accept-actions">
                    <button class="button button-primary" type="submit">Accept Challenge</button>
                    <?php if (!fc_is_logged_in()): ?><span class="fc-ui-help">After you accept, choose how you want to sign in or create your FitCrew account.</span><?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>
