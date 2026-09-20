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

            <?php if ($recipientEmail !== ''): ?>
                <div class="fc-notice fc-notice-account">
                    <strong>Invitation email</strong>
                    <span><?= fc_e($recipientEmail) ?></span>
                    <?php if ($emailContext !== null): ?>
                        <span>This secure invitation verifies access to this email. You do not need a second email sign-in link.</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($signedInUser !== null): ?>
                <?php
                $displayName = trim((string) ($signedInUser['display_name'] ?? 'FitCrew member')) ?: 'FitCrew member';
                $verifiedEmail = $signedInEmail ?? 'No verified contact email on this account';
                ?>
                <div class="fc-notice fc-notice-account">
                    <strong>Currently signed in as <?= fc_e($displayName) ?></strong>
                    <span><?= fc_e($verifiedEmail) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($profileRequired): ?>
                <section class="fc-ui-card fc-ui-card-soft challenge-review-section">
                    <p class="fc-ui-eyebrow">Finish your FitCrew account</p>
                    <h2 class="fc-ui-section-title">One account detail is still needed.</h2>
                    <p class="fc-ui-body">Your Challenge acceptance is preserved. FitCrew already verified <strong><?= fc_e($recipientEmail) ?></strong> through this invitation. Add the name you want shown in FitCrew; you will not be asked to accept the Challenge again unless its terms change.</p>
                    <form method="post" action="/crew-invite.php" class="product-form challenge-profile-form">
                        <?= fc_csrf_input() ?>
                        <input type="hidden" name="action" value="complete_profile">
                        <label>Display name
                            <input type="text" name="display_name" maxlength="120" autocomplete="name" required>
                        </label>
                        <button class="button button-primary" type="submit">Create Account &amp; Join Challenge</button>
                    </form>
                </section>
            <?php else: ?>
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
                        <span>I accept these Challenge Rules and understand that accepting joins the Crew and this Challenge with the FitCrew account I use. This does not authorize health-data collection.</span>
                    </label>

                    <div class="challenge-accept-actions">
                        <?php if ($emailContext !== null && $recipientEmail !== ''): ?>
                            <button class="button button-primary" type="submit">Accept Challenge as <?= fc_e($recipientEmail) ?></button>
                            <span class="fc-ui-help">If this email already belongs to FitCrew, you’ll be signed in and enrolled. If you’re new, FitCrew will ask only for the account information still required.</span>
                        <?php else: ?>
                            <button class="button button-primary" type="submit">Accept Challenge</button>
                            <span class="fc-ui-help">You chose an existing FitCrew account under another email. Accepting uses the authenticated account shown above.</span>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>

            <form method="post" action="/crew-invite.php" class="challenge-account-switch">
                <?= fc_csrf_input() ?>
                <input type="hidden" name="action" value="use_different_account">
                <button class="button button-secondary" type="submit">Use a different FitCrew account</button>
                <span class="fc-ui-help">Use this only if your existing FitCrew account is under a different email address.</span>
            </form>
        <?php endif; ?>
    </div>
</section>
