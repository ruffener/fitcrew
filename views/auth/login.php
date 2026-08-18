<?php
$isCreateIntent = ($entryIntent ?? 'signin') === 'create';
?>
<section class="auth-card account-entry-card">
    <span class="status-chip status-chip-neutral"><span aria-hidden="true">○</span> Provider foundation ready</span>
    <p class="eyebrow">Account access</p>
    <h1><?= $isCreateIntent ? 'Create your FitCrew Challenge account.' : 'Continue to FitCrew Challenge.' ?></h1>
    <p class="auth-intro">
        FitCrew Challenge uses provider-based sign-in—no FitCrew password required.
        Google, Microsoft, and Apple authentication are being enabled in separately proven phases.
    </p>

    <div class="provider-choice-list" aria-label="Authentication providers">
        <button class="provider-choice" type="button" disabled>
            <span class="provider-mark" aria-hidden="true">G</span>
            <span class="provider-choice-copy">
                <strong>Continue with Google</strong>
                <small>Next provider slice</small>
            </span>
            <span class="provider-status">Coming next</span>
        </button>
        <button class="provider-choice" type="button" disabled>
            <span class="provider-mark" aria-hidden="true">M</span>
            <span class="provider-choice-copy">
                <strong>Continue with Microsoft</strong>
                <small>Separate provider slice</small>
            </span>
            <span class="provider-status">Planned</span>
        </button>
        <button class="provider-choice" type="button" disabled>
            <span class="provider-mark" aria-hidden="true">A</span>
            <span class="provider-choice-copy">
                <strong>Continue with Apple</strong>
                <small>Separate provider slice</small>
            </span>
            <span class="provider-status">Planned</span>
        </button>
    </div>

    <div class="account-entry-note">
        <strong>Identity and health data stay separate.</strong>
        <p>Signing in proves who you are. Connecting a health-data provider is a different permission and remains separate future work.</p>
    </div>

    <p class="placeholder-note">
        These provider controls are intentionally disabled until their individual authentication phases are authorized and proven.
    </p>

    <div class="auth-links auth-links-single">
        <?php if ($isCreateIntent): ?>
            <a href="/login.php">Already have a FitCrew Challenge account? Sign in</a>
        <?php else: ?>
            <a href="/register.php">New to FitCrew Challenge? Create account</a>
        <?php endif; ?>
    </div>
</section>
