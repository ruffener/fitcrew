<?php
$isCreateIntent = ($entryIntent ?? 'signin') === 'create';
$googleEnabled = (bool) ($googleAuthConfig['enabled'] ?? false);
?>
<section class="auth-card account-entry-card">
    <span class="status-chip status-chip-neutral"><span aria-hidden="true">○</span> Provider authentication</span>
    <p class="eyebrow">Account access</p>
    <h1><?= $isCreateIntent ? 'Create your FitCrew Challenge account.' : 'Continue to FitCrew Challenge.' ?></h1>
    <p class="auth-intro">
        FitCrew Challenge uses provider-based sign-in—no FitCrew password required.
        Authentication and health-data permissions remain separate.
    </p>

    <div class="provider-choice-list" aria-label="Authentication providers">
        <?php if ($googleEnabled): ?>
            <div
                class="google-provider-shell"
                id="fitcrew-google-auth"
                data-client-id="<?= fc_e((string) $googleAuthConfig['client_id']) ?>"
                data-transaction-id="<?= fc_e((string) $googleAuthConfig['transaction_id']) ?>"
                data-state="<?= fc_e((string) $googleAuthConfig['state']) ?>"
                data-nonce="<?= fc_e((string) $googleAuthConfig['nonce']) ?>"
                data-csrf-token="<?= fc_e((string) $googleAuthConfig['csrf_token']) ?>"
                data-endpoint="<?= fc_e((string) $googleAuthConfig['endpoint']) ?>"
            >
                <div id="fitcrew-google-button" class="google-button-host" aria-label="Continue with Google"></div>
                <p id="fitcrew-google-message" class="provider-help" aria-live="polite">Loading secure Google sign-in…</p>
            </div>
        <?php else: ?>
            <button class="provider-choice" type="button" disabled>
                <span class="provider-mark" aria-hidden="true">G</span>
                <span class="provider-choice-copy">
                    <strong>Continue with Google</strong>
                    <small><?= fc_e((string) ($googleAuthConfig['reason'] ?? 'Authentication setup required')) ?></small>
                </span>
                <span class="provider-status">Setup required</span>
            </button>
        <?php endif; ?>

        <button class="provider-choice" type="button" disabled>
            <span class="provider-mark" aria-hidden="true">A</span>
            <span class="provider-choice-copy">
                <strong>Continue with Apple</strong>
                <small>Separate provider slice</small>
            </span>
            <span class="provider-status">Planned</span>
        </button>
        <button class="provider-choice" type="button" disabled>
            <span class="provider-mark" aria-hidden="true">M</span>
            <span class="provider-choice-copy">
                <strong>Continue with Microsoft</strong>
                <small>Separate provider slice</small>
            </span>
            <span class="provider-status">Planned</span>
        </button>
    </div>

    <div class="account-entry-note">
        <strong>Identity and health data stay separate.</strong>
        <p>Continue with Google proves who you are to FitCrew Challenge. It does not connect Google Health or grant health-data access.</p>
    </div>

    <?php if (!$googleEnabled): ?>
        <p class="placeholder-note">Google authentication is not enabled until its dedicated client configuration is complete.</p>
    <?php endif; ?>
</section>
