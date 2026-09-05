<section class="product-hero compact-product-hero">
    <div><p class="eyebrow">New Challenge</p><h1>Build the next challenge.</h1><p>Start with the Challenge shell. Published Rules establish the governed contract before the lifecycle moves forward.</p></div>
    <span class="status-chip status-chip-orange">Draft</span>
</section>
<section class="product-card product-card-accent-orange">
    <p class="card-kicker">Create Challenge</p><h2>Set the shell. Publish the rules next.</h2>
    <form class="product-form product-form-grid" method="post" action="/challenge.php">
        <?= fc_csrf_input() ?><input type="hidden" name="action" value="create_challenge">
        <label class="form-span-2">Challenge name<input type="text" name="display_name" maxlength="140" required placeholder="Fall FitCrew Challenge"></label>
        <label>Planned start<input type="date" name="planned_start_date"></label>
        <label>Duration<input type="number" name="duration_days" min="7" max="365" value="56" required><span>days</span></label>
        <label>Weekly check-in day<select name="weekly_checkin_day"><option value="0">Sunday</option><option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option><option value="6">Saturday</option></select></label>
        <label>Challenge timezone<select name="challenge_timezone"><option value="America/New_York">Eastern Time</option><option value="America/Chicago">Central Time</option><option value="America/Denver">Mountain Time</option><option value="America/Phoenix">Arizona</option><option value="America/Los_Angeles">Pacific Time</option><option value="America/Anchorage">Alaska</option><option value="Pacific/Honolulu">Hawaii</option></select></label>
        <label class="checkbox-field form-span-2"><input type="checkbox" name="live_leaderboard_visible" value="1" checked><span>Allow Live — Provisional standings when an authoritative scoring service supplies them.</span></label>
        <div class="form-span-2 form-actions"><button class="button button-primary" type="submit">Create Challenge Draft</button><?php if (!empty($challenge)): ?><a class="button button-secondary" href="/challenge.php">Cancel</a><?php endif; ?></div>
    </form>
</section>
