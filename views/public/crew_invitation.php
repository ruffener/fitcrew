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

            <?php
            $accountName = (string) ($signedInUser['display_name'] ?? 'FitCrew account');
            $accountLabel = $accountName . ($signedInEmail !== null ? ' (' . $signedInEmail . ')' : ' — no verified email');
            ?>
            <div class="inline-empty-state">
                <strong>You’re signed in as <?= fc_e($accountLabel) ?>.</strong>
                <span>This invitation was sent to <?= fc_e((string) $invitation['invited_email']) ?>. Choose the account you want to join with.</span>
            </div>

            <form method="post" action="/auth/invitation/switch-account.php" class="product-form">
                <?= fc_csrf_input() ?>
                <button class="button button-secondary" type="submit">Sign out and use a different account</button>
                <p class="form-help">Your invitation will stay open while you sign in again. To use <?= fc_e((string) $invitation['invited_email']) ?>, sign in with that email.</p>
            </form>

            <?php if ($alreadyMember): ?>
                <div class="inline-empty-state">
                    <strong><?= fc_e($accountLabel) ?> is already in this Crew.</strong>
                    <span>Switch accounts to use this invitation, or open your Crew. This invitation will remain available.</span>
                    <form method="post" action="/crew.php"><?= fc_csrf_input() ?><button class="button button-secondary" type="submit" name="select_crew" value="<?= fc_e((string) $invitation['crew_public_id']) ?>">Open your Crew</button></form>
                </div>
            <?php endif; ?>

            <form method="post" action="/crew-invite.php" class="product-form">
                <?= fc_csrf_input() ?>
                <p class="form-help">Accepting joins the Crew with the account shown above.</p>
                <button class="button button-primary" type="submit"<?= $alreadyMember ? ' disabled' : '' ?>>
                    Accept as <?= fc_e($accountLabel) ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</section>
