<section class="panel">
    <p class="eyebrow">Account access</p>
    <h1>Login Placeholder</h1>
    <p>Email/password login is approved for MVP, but real account behavior is not implemented in Phase 1 skeleton.</p>
    <form class="form-placeholder" method="post" action="#">
        <?php require fc_path('views/partials/csrf.php'); ?>
        <label>Email <input type="email" name="email" disabled></label>
        <label>Password <input type="password" name="password" disabled></label>
        <button type="button" disabled>Login Not Implemented</button>
    </form>
</section>
