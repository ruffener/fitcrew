<section class="auth-card">
    <span class="status-chip status-chip-neutral"><span aria-hidden="true">○</span> Phase 2A preview</span>
    <p class="eyebrow">Account recovery</p>
    <h1>Choose a new password.</h1>
    <p class="auth-intro">Reset-token handling and password replacement are not implemented. The controls remain disabled until the account foundation is authorized.</p>
    <form class="form-placeholder" method="post" action="#">
        <?php require fc_path('views/partials/csrf.php'); ?>
        <label>New password <input type="password" name="password" placeholder="New password" disabled></label>
        <label>Confirm password <input type="password" name="password_confirmation" placeholder="Confirm password" disabled></label>
        <button class="button button-primary button-full" type="button" disabled>Update Password — Not Implemented</button>
    </form>
</section>
