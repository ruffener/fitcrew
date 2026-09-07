<section class="product-hero compact-product-hero">
    <div><p class="eyebrow">New Challenge</p><h1>Build the next challenge.</h1><p>Start with the Challenge details. Review and publish the Rules next.</p></div>
    <span class="status-chip status-chip-orange">Draft</span>
</section>
<section class="product-card product-card-accent-orange">
    <div class="section-bar setup-section-bar">
        <div><p class="card-kicker">Create Challenge</p><h2>Set the details. Review the rules next.</h2></div>
        <div class="setup-progress" aria-label="Challenge setup, step 1 of 2">
            <span>Challenge setup</span>
            <strong>Step 1 of 2</strong>
            <span class="setup-progress-track" aria-hidden="true"><i style="width:50%"></i></span>
        </div>
    </div>
    <form class="product-form product-form-grid" method="post" action="/challenge.php" data-challenge-dates data-default-duration="84">
        <?= fc_csrf_input() ?><input type="hidden" name="action" value="create_challenge">
        <input type="hidden" name="duration_days" value="84" data-duration-days>
        <label class="form-span-2">Challenge name<input type="text" name="display_name" maxlength="140" required placeholder="Fall FitCrew Challenge"></label>
        <label>Planned start<input type="date" name="planned_start_date" data-planned-start></label>
        <label>Planned end<input type="date" name="planned_end_date" data-planned-end><span data-duration-summary>12 weeks · 84 days</span></label>
        <label>Weekly check-in day<select name="weekly_checkin_day"><option value="0">Sunday</option><option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option><option value="6" selected>Saturday</option></select></label>
        <label>Challenge timezone<select name="challenge_timezone"><option value="America/New_York">Eastern Time</option><option value="America/Chicago">Central Time</option><option value="America/Denver">Mountain Time</option><option value="America/Phoenix">Arizona</option><option value="America/Los_Angeles">Pacific Time</option><option value="America/Anchorage">Alaska</option><option value="Pacific/Honolulu">Hawaii</option></select></label>
        <label class="checkbox-field form-span-2"><input type="checkbox" name="live_leaderboard_visible" value="1" checked><span><strong>Show provisional standings while the Challenge is live.</strong><small>They appear only when official scoring data is available.</small></span></label>
        <div class="form-span-2 form-actions"><button class="button button-primary" type="submit">Create Challenge Draft</button><?php if (!empty($challenge)): ?><a class="button button-secondary" href="/challenge.php">Cancel</a><?php endif; ?></div>
    </form>
</section>
