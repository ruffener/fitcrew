<section class="auth-card">
    <span class="status-chip status-chip-neutral"><span aria-hidden="true">○</span> Phase 2A preview</span>
    <p class="eyebrow">Account access</p>
    <h1>Welcome back.</h1>
    <p class="auth-intro">The visual pattern for FitCrew Challenge account access is ready. Real authentication is intentionally not implemented in Phase 1B.</p>
    <form class="form-placeholder" method="post" action="#" aria-describedby="login-placeholder-note">
        <?php require fc_path('views/partials/csrf.php'); ?>
        <label>Email address <input type="email" name="email" autocomplete="email" placeholder="you@example.com" disabled></label>
        <label>Password <input type="password" name="password" autocomplete="current-password" placeholder="Password" disabled></label>
        <button class="button button-primary button-full" type="button" disabled>Sign In — Not Implemented</button>
    </form>
    <p class="placeholder-note" id="login-placeholder-note">Account behavior begins only after Phase 2A authorization.</p>
    <div class="auth-links">
        <a href="/forgot-password.php">Forgot password preview</a>
        <a href="/register.php">Registration preview</a>
    </div>
    <div class="auth-create-account-callout">
        <h2>Need an account?</h2>
        <p>If you're new to FitCrew Challenge, start with the registration preview. This keeps Create Account visible without crowding the top navigation.</p>
        <a class="button button-energy" href="/register.php">Create Account</a>
    </div>
</section>
