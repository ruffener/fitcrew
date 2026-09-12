<section class="product-hero compact-product-hero">
    <div>
        <p class="eyebrow">New Challenge</p>
        <h1>Build the next challenge.</h1>
        <p>Set the basics here. Review and publish the Rules next.</p>
    </div>
    <span class="status-chip status-chip-orange">Draft</span>
</section>

<section class="product-card product-card-accent-orange">
    <div class="section-bar setup-section-bar">
        <div>
            <p class="card-kicker">Create Challenge</p>
            <h2>Step 1 — Set the Challenge details.</h2>
        </div>
        <div class="setup-progress" aria-label="Challenge setup, step 1 of 2">
            <span>Challenge setup</span>
            <strong>Step 1 of 2</strong>
            <span class="setup-progress-track" aria-hidden="true"><i style="width:50%"></i></span>
        </div>
    </div>

    <form class="product-form product-form-grid" method="post" action="/challenge.php" data-challenge-dates data-default-duration="84">
        <?= fc_csrf_input() ?>
        <input type="hidden" name="crew_public_id" value="<?= fc_e((string)$crew['public_id']) ?>">
        <input type="hidden" name="action" value="create_challenge">
        <input type="hidden" name="duration_days" value="84" data-duration-days>

        <label class="form-span-2">Challenge name
            <input type="text" name="display_name" maxlength="140" required placeholder="Fall FitCrew Challenge">
        </label>

        <label>Begin date
            <input type="date" name="planned_start_date" required data-planned-start>
        </label>

        <fieldset class="challenge-finish-fieldset">
            <legend>Choose how the Challenge ends</legend>
            <div class="challenge-finish-options" role="radiogroup" aria-label="Challenge finish method">
                <label class="challenge-finish-option">
                    <input type="radio" name="finish_mode" value="duration" checked data-finish-mode>
                    <span><strong>Duration</strong><small>Choose the number of weeks.</small></span>
                </label>
                <label class="challenge-finish-option">
                    <input type="radio" name="finish_mode" value="end_date" data-finish-mode>
                    <span><strong>End date</strong><small>Choose the exact finish date.</small></span>
                </label>
            </div>
        </fieldset>

        <div class="challenge-finish-panel form-span-2" data-finish-panel="duration">
            <label>Duration
                <span class="input-with-suffix">
                    <input type="number" min="1" max="52" step="1" value="12" inputmode="numeric" data-duration-weeks>
                    <span>weeks</span>
                </span>
            </label>
            <div class="calculated-field">
                <span>Calculated end date</span>
                <strong data-calculated-end>Choose a begin date</strong>
            </div>
        </div>

        <div class="challenge-finish-panel form-span-2" data-finish-panel="end_date" hidden>
            <label>End date
                <input type="date" name="planned_end_date" data-planned-end disabled>
            </label>
            <div class="calculated-field">
                <span>Calculated duration</span>
                <strong data-duration-summary>12 weeks · 84 days</strong>
            </div>
        </div>

        <label>Weekly check-in day
            <select name="weekly_checkin_day">
                <option value="0">Sunday</option>
                <option value="1">Monday</option>
                <option value="2">Tuesday</option>
                <option value="3">Wednesday</option>
                <option value="4">Thursday</option>
                <option value="5">Friday</option>
                <option value="6" selected>Saturday</option>
            </select>
        </label>

        <label>Challenge timezone
            <select name="challenge_timezone">
                <option value="America/New_York">Eastern Time</option>
                <option value="America/Chicago">Central Time</option>
                <option value="America/Denver">Mountain Time</option>
                <option value="America/Phoenix">Arizona</option>
                <option value="America/Los_Angeles">Pacific Time</option>
                <option value="America/Anchorage">Alaska</option>
                <option value="Pacific/Honolulu">Hawaii</option>
            </select>
        </label>

        <label class="checkbox-field form-span-2">
            <input type="checkbox" name="live_leaderboard_visible" value="1" checked>
            <span>
                <strong>Show provisional standings while the Challenge is live.</strong>
                <small>They appear only when official scoring data is available.</small>
            </span>
        </label>

        <div class="form-span-2 form-actions">
            <button class="button button-primary" type="submit">Continue to Rules</button>
            <?php if (!empty($challenge)): ?><a class="button button-secondary" href="/challenge.php">Cancel</a><?php endif; ?>
        </div>
    </form>
</section>
