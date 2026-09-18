<?php /** @var bool $linkRecent */ ?>
<section class="auth-email-setup">
    <p class="eyebrow">Your sign-in methods</p>
    <h1>Add email sign-in</h1>
    <?php if ($linkRecent): ?>
        <p>Add an email sign-in method to the FitCrew account you are signed into now. Your existing sign-in method will keep working.</p>
        <form class="form-placeholder" method="post" action="/auth/email/link.php">
            <input type="hidden" name="csrf_token" value="<?= fc_e(fc_csrf_token()) ?>">
            <label for="fitcrew-link-email">Email address you control</label>
            <input id="fitcrew-link-email" name="email" type="email" inputmode="email" autocomplete="email" maxlength="254" required aria-describedby="fitcrew-link-help">
            <p id="fitcrew-link-help">We will send a confirmation link. Open it in this same browser while still signed in, then confirm adding email sign-in.</p>
            <button class="button button-primary" type="submit">Send confirmation to add email sign-in</button>
        </form>
        <p>The confirmation expires within 10 minutes and requires a recent sign-in. After setup, ordinary email sign-in links can be used in any browser.</p>
    <?php else: ?>
        <p>For account security, adding a sign-in method requires a sign-in within the last 10 minutes.</p>
        <p>Sign out, return to <a href="/auth/email/link.php">Add email sign-in</a>, and sign in again with your existing method.</p>
        <form method="post" action="/logout.php">
            <input type="hidden" name="csrf_token" value="<?= fc_e(fc_csrf_token()) ?>">
            <button class="button button-primary" type="submit">Sign out</button>
        </form>
    <?php endif; ?>
    <p><a href="/app.php">Return to FitCrew</a></p>
</section>
