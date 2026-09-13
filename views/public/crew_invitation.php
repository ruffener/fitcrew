<section class="public-section public-section-compact">
    <div class="public-card">
        <p class="eyebrow">Crew Invitation</p>

        <?php if (!fc_is_logged_in()): ?>
            <h1>Open your latest Crew invitation.</h1>
            <p>FitCrew uses the private invitation link to begin the secure sign-in and acceptance flow.</p>
        <?php elseif ($continuation === null): ?>
            <h1>Invitation unavailable.</h1>
            <p>This browser does not have a current authenticated Crew invitation. Open the latest invitation email and try again.</p>
        <?php elseif ($invitation === null): ?>
            <h1>This invitation changed or expired.</h1>
            <p>The Owner may have resent or cancelled it, or the invitation may have expired. Open the latest valid invitation email.</p>
        <?php else: ?>
            <h1>Join <?= fc_e((string)$invitation['crew_name']) ?>.</h1>
            <p><?= fc_e((string)($invitation['inviter_name'] ?: 'A FitCrew member')) ?> invited you to this private Crew.</p>

            <div class="inline-empty-state">
                <strong>You’re signed in as <?= fc_e((string)($signedInUser['display_name'] ?? 'this FitCrew account')) ?>.</strong>
                <span>Acceptance applies to this signed-in FitCrew account. The invitation email address is delivery-only and is not used as identity.</span>
            </div>

            <?php if ($alreadyMember): ?>
                <div class="inline-empty-state">
                    <strong>This account is already in the Crew.</strong>
                    <span>Accepting will close this invitation without creating a duplicate membership or changing an existing Owner role.</span>
                </div>
            <?php endif; ?>

            <form method="post" action="/crew-invite.php" class="product-form">
                <?= fc_csrf_input() ?>
                <p class="form-help">Joining remains your choice. Authentication alone does not create Crew membership.</p>
                <button class="button button-primary" type="submit">
                    Accept as <?= fc_e((string)($signedInUser['display_name'] ?? 'this FitCrew account')) ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</section>
