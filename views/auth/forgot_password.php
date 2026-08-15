<section class="auth-card">
    <span class="status-chip status-chip-neutral"><span aria-hidden="true">○</span> Phase 2A preview</span>
    <p class="eyebrow">Account recovery</p>
    <h1>Reset access.</h1>
    <p class="auth-intro">Password recovery is not active yet. This placeholder demonstrates the approved FitCrew Challenge form pattern without creating working account behavior.</p>
    <form class="form-placeholder" method="post" action="#">
        <?php require fc_path('views/partials/csrf.php'); ?>
        <label>Email address <input type="email" name="email" placeholder="you@example.com" disabled></label>
        <button class="button button-primary button-full" type="button" disabled>Send Reset Link — Not Implemented</button>
    </form>
    <div class="auth-links"><a href="/login.php">Back to sign-in preview</a></div>
</section>
