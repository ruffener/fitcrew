<section class="auth-card">
    <span class="status-chip status-chip-neutral"><span aria-hidden="true">○</span> Phase 2A preview</span>
    <p class="eyebrow">New account</p>
    <h1>Join FitCrew Challenge.</h1>
    <p class="auth-intro">This screen establishes the future account-form style only. Registration behavior is intentionally unavailable in Phase 1B.</p>
    <form class="form-placeholder" method="post" action="#">
        <?php require fc_path('views/partials/csrf.php'); ?>
        <label>Display name <input type="text" name="display_name" placeholder="Your name" disabled></label>
        <label>Email address <input type="email" name="email" placeholder="you@example.com" disabled></label>
        <label>Password <input type="password" name="password" placeholder="Password" disabled></label>
        <button class="button button-primary button-full" type="button" disabled>Create Account — Not Implemented</button>
    </form>
    <p class="placeholder-note">Google login, Apple login, group membership, and health-provider connection remain separate future work.</p>
</section>
