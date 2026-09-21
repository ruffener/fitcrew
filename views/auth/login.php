<?php
$isCreateIntent = ($entryIntent ?? 'signin') === 'create';
$googleEnabled = (bool) ($googleAuthConfig['enabled'] ?? false);
$microsoftVisible = (bool) ($microsoftAuthConfig['visible'] ?? false);
$microsoftEnabled = (bool) ($microsoftAuthConfig['enabled'] ?? false);
$emailRequestEndpoint = (string) ($emailAuthConfig['request_endpoint'] ?? '/auth/email/request.php');
$emailCsrfToken = (string) ($emailAuthConfig['csrf_token'] ?? fc_csrf_token());
?>
<section class="auth-card account-entry-card">
    <span class="status-chip status-chip-neutral"><span aria-hidden="true">○</span> Secure sign-in</span>
    <h1 class="fc-type-page-title"><?= $isCreateIntent ? 'Create your account' : 'Sign in to FitCrew' ?></h1>
    <p class="auth-intro">
        Continue with Google or an email link. No FitCrew password required.
    </p>

    <div class="provider-choice-list" aria-label="Sign-in options">
        <?php if ($googleEnabled): ?>
            <div
                class="google-provider-shell"
                id="fitcrew-google-auth"
                data-client-id="<?= fc_e((string) $googleAuthConfig['client_id']) ?>"
                data-transaction-id="<?= fc_e((string) $googleAuthConfig['transaction_id']) ?>"
                data-state="<?= fc_e((string) $googleAuthConfig['state']) ?>"
                data-nonce="<?= fc_e((string) $googleAuthConfig['nonce']) ?>"
                data-expires-at="<?= fc_e((string) $googleAuthConfig['expires_at']) ?>"
                data-csrf-token="<?= fc_e((string) $googleAuthConfig['csrf_token']) ?>"
                data-endpoint="<?= fc_e((string) $googleAuthConfig['endpoint']) ?>"
                data-refresh-endpoint="<?= fc_e((string) $googleAuthConfig['refresh_endpoint']) ?>"
            >
                <div id="fitcrew-google-button" class="google-button-host" aria-label="Continue with Google"></div>
                <p id="fitcrew-google-message" class="provider-help fc-notice fc-notice-info" role="status" aria-live="polite" aria-atomic="true">Loading secure Google sign-in…</p>
            </div>
        <?php else: ?>
            <button class="provider-choice" type="button" disabled>
                <span class="provider-mark" aria-hidden="true">G</span>
                <span class="provider-choice-copy">
                    <strong>Continue with Google</strong>
                    <small><?= fc_e((string) ($googleAuthConfig['reason'] ?? 'Authentication setup required')) ?></small>
                </span>
                <span class="provider-status"><?= fc_e((string) ($googleAuthConfig['status'] ?? 'Setup required')) ?></span>
            </button>
        <?php endif; ?>

        <form class="email-provider-form form-placeholder" method="post" action="<?= fc_e($emailRequestEndpoint) ?>">
            <input type="hidden" name="csrf_token" value="<?= fc_e($emailCsrfToken) ?>">
            <label for="fitcrew-email-auth">Continue with email</label>
            <input
                id="fitcrew-email-auth"
                name="email"
                type="email"
                inputmode="email"
                autocomplete="email"
                maxlength="254"
                placeholder="you@example.com"
                aria-describedby="fitcrew-email-help"
                required
            >
            <button class="button button-primary" type="submit">Email me a sign-in link</button>
            <p id="fitcrew-email-help" class="provider-help">The one-time link expires in 15 minutes and may be opened on any browser or device.</p>
        </form>

        <button class="provider-choice" type="button" disabled>
            <span class="provider-mark" aria-hidden="true">A</span>
            <span class="provider-choice-copy">
                <strong>Continue with Apple</strong>
                <small>Not available yet</small>
            </span>
            <span class="provider-status">Planned</span>
        </button>
        <?php if ($microsoftVisible): ?>
            <?php if ($microsoftEnabled): ?>
                <form class="microsoft-provider-form" method="post" action="<?= fc_e((string) $microsoftAuthConfig['start_endpoint']) ?>">
                    <input type="hidden" name="csrf_token" value="<?= fc_e((string) $microsoftAuthConfig['csrf_token']) ?>">
                    <button class="provider-choice" type="submit">
                        <span class="provider-mark provider-mark-microsoft" aria-hidden="true">M</span>
                        <span class="provider-choice-copy">
                            <strong>Continue with Microsoft</strong>
                            <small>Personal and work/school Microsoft accounts</small>
                        </span>
                        <span class="provider-status">Available</span>
                    </button>
                </form>
            <?php else: ?>
                <button class="provider-choice" type="button" disabled>
                    <span class="provider-mark" aria-hidden="true">M</span>
                    <span class="provider-choice-copy">
                        <strong>Continue with Microsoft</strong>
                        <small><?= fc_e((string) ($microsoftAuthConfig['reason'] ?? 'Authentication setup required')) ?></small>
                    </span>
                    <span class="provider-status">Setup required</span>
                </button>
            <?php endif; ?>
        <?php endif; ?>
    </div>


    <div class="account-entry-note fc-notice fc-notice-info">
        <strong>Sign-in &amp; security</strong>
        <p>Sign-in is separate from your Crew and Challenge roles. It does not connect health data or grant health-data access.</p>
    </div>


</section>
