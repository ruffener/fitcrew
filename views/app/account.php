<section class="product-hero compact-product-hero">
    <div>
        <p class="eyebrow">Account</p>
        <h1>Your FitCrew account.</h1>
        <p>Account details live here so Overview can stay focused on your Crew, Challenge, and next action.</p>
    </div>
    <span class="status-chip status-chip-success"><?= fc_e(ucfirst(strtolower((string) ($currentUser['account_status'] ?? 'active')))) ?></span>
</section>

<section class="split-card-grid">
    <article class="product-card product-card-accent-blue">
        <p class="card-kicker">Profile</p>
        <h2><?= fc_e((string) ($currentUser['display_name'] ?: 'FitCrew member')) ?></h2>
        <dl class="account-facts">
            <div><dt>FitCrew Member ID</dt><dd class="account-id"><?= fc_e((string) ($currentUser['public_id'] ?? '')) ?></dd></div>
            <div><dt>Timezone</dt><dd><?= fc_e((string) ($currentUser['timezone'] ?: fc_config()['timezone'])) ?></dd></div>
            <div><dt>Locale</dt><dd><?= fc_e((string) ($currentUser['locale'] ?: 'Default')) ?></dd></div>
        </dl>
        <p class="form-help">Share your Member ID with a Crew Owner when they need to add your existing FitCrew account during Family Alpha.</p>
    </article>
    <article class="product-card">
        <p class="card-kicker">Sign-in & security</p>
        <h2>Federated account access</h2>
        <p>Your current FitCrew session was established through <?= fc_e(ucfirst(strtolower((string) ($currentUser['provider_key'] ?? 'your provider')))) ?>. Provider-specific authentication and account-security behavior is managed separately from Crew and Challenge membership.</p>
        <span class="status-chip status-chip-neutral">Authentication separate from product roles</span>
    </article>
</section>
