<fieldset class="family-privacy-fields">
    <legend>Your sharing choices for this Challenge</legend>
    <div class="family-privacy-required"><strong>Competition results · Challenge-visible</strong><p>While you compete, authorized people in this Challenge can see your display name, participation, Official improvement score and rank when results are available. Crew membership alone does not grant that access.</p></div>
    <label>Official Measurements
        <select name="measurements_visibility">
            <option value="PRIVATE"<?= $privacy['measurements_visibility'] === 'PRIVATE' ? ' selected' : '' ?>>Private — only me</option>
            <option value="CHALLENGE"<?= $privacy['measurements_visibility'] === 'CHALLENGE' ? ' selected' : '' ?>>Share approved Official Measurements with this Challenge</option>
        </select>
        <span class="form-help">Private by default. Sharing does not grant permission to collect health data.</span>
    </label>
    <label>Personal Progress
        <select name="progress_visibility">
            <option value="PRIVATE"<?= $privacy['progress_visibility'] === 'PRIVATE' ? ' selected' : '' ?>>Private — only me</option>
            <option value="CHALLENGE"<?= $privacy['progress_visibility'] === 'CHALLENGE' ? ' selected' : '' ?>>Share approved Personal Progress with this Challenge</option>
        </select>
    </label>
    <div class="family-privacy-required"><strong>Raw Health / Provider Data · Always private</strong><p>Raw provider records and payloads are never shared with the ordinary Challenge audience. The Owner cannot change your sharing choices.</p></div>
    <p class="form-help">Measurements, progress and scoring are not available yet. These choices do not connect a provider, submit measurements or create results. They apply only to approved features as those become available.</p>
</fieldset>
