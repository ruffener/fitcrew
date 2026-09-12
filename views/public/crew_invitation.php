<section class="public-section public-section-compact">
    <div class="public-card">
        <p class="eyebrow">Crew Invitation</p>
        <?php if ($invitation === null): ?>
            <h1>Invitation unavailable.</h1>
            <p>This invitation link is invalid or no longer available.</p>
        <?php elseif ((string)$invitation['invitation_status'] !== 'PENDING'): ?>
            <h1>This invitation is <?= fc_e(strtolower((string)$invitation['invitation_status'])) ?>.</h1>
            <p>Ask the Crew Owner for a new invitation if you still want to join.</p>
        <?php else: ?>
            <h1>Join <?= fc_e((string)$invitation['crew_name']) ?>.</h1>
            <p><?= fc_e((string)($invitation['inviter_name'] ?: 'A FitCrew member')) ?> invited you to this private Crew.</p>
            <?php if (!fc_is_logged_in()): ?>
                <div class="inline-empty-state"><strong>Sign in before accepting.</strong><span>FitCrew membership is bound to your authenticated FitCrew account, not the email address that received this invitation. After signing in, return to this invitation link.</span><a class="button button-primary" href="/login.php" target="_blank" rel="noopener">Sign In to FitCrew</a></div>
            <?php else: ?>
                <form method="post" action="/crew-invite.php" class="product-form">
                    <?= fc_csrf_input() ?>
                    <input type="hidden" name="token" value="<?= fc_e($token) ?>">
                    <p class="form-help">Accepting makes your signed-in FitCrew account a member of this Crew. The invitation email address is not used as identity.</p>
                    <button class="button button-primary" type="submit">Accept Crew Invitation</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
