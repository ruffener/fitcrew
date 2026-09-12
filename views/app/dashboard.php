<?php
$crew = $appContext['crew'];
$challenge = $appContext['challenge'];
$firstName = trim((string) ($currentUser['display_name'] ?? ''));
$welcomeName = $firstName !== '' ? $firstName : 'there';
?>
<section class="product-hero product-hero-overview">
    <div>
        <p class="eyebrow">Overview</p>
        <h1>Good to see you, <?= fc_e($welcomeName) ?>.</h1>
        <?php if ($crew === null): ?>
            <p>FitCrew starts with your people. Create a private Crew, then build your first Challenge together.</p>
        <?php elseif ($challenge === null): ?>
            <p><strong><?= fc_e((string) $crew['display_name']) ?></strong> is ready. Choose a Challenge below or create the next one.</p>
        <?php else: ?>
            <p>You’re in <strong><?= fc_e((string) $crew['display_name']) ?></strong>. Choose a Challenge below to see its status, people, rules, and progress.</p>
        <?php endif; ?>
    </div>
</section>

<?php if ($crew === null): ?>
<section class="empty-state-feature">
    <div class="empty-state-icon" aria-hidden="true">FC</div>
    <div>
        <p class="card-kicker">Your Crew</p>
        <h2>Bring your people together.</h2>
        <p>A Crew is your private home base. Challenges come and go; your Crew stays together.</p>
        <p class="action-cue">Start here</p>
        <a class="button button-primary" href="/crew.php">Create your Crew</a>
    </div>
</section>
<?php else: ?>
<?php
$crewChallenges = fc_challenge_summaries_for_crew($pdo, (int) $currentUser['user_id'], (int) $crew['id'], false);
$isCrewOwner = (string) $crew['membership_role'] === 'OWNER';
?>
<section class="overview-challenges" aria-labelledby="overview-challenges-title">
    <div class="overview-challenges-heading">
        <div>
            <p class="card-kicker">Your Challenges</p>
            <h2 id="overview-challenges-title">Choose your competition.</h2>
            <p>Each Challenge keeps its own lifecycle, participation, rules, and results in one place.</p>
        </div>
    </div>

    <?php if ($crewChallenges === []): ?>
        <div class="overview-challenge-empty">
            <div>
                <strong>No Challenge yet.</strong>
                <span><?= $isCrewOwner ? 'Create the first Challenge when your Crew is ready.' : 'Your Crew Owner has not created a Challenge yet.' ?></span>
            </div>
            <a class="button button-secondary" href="/challenge.php">Open Challenge List</a>
        </div>
    <?php else: ?>
        <div class="overview-challenge-list">
            <?php foreach ($crewChallenges as $crewChallenge): ?>
                <?php
                $challengeId = (int) $crewChallenge['id'];
                $hasAccess = fc_challenge_user_has_access($pdo, (int) $currentUser['user_id'], $challengeId);
                $participation = $hasAccess ? fc_challenge_participation_for_user($pdo, $challengeId, (int) $currentUser['user_id']) : null;
                $challengeOwner = (int) $crewChallenge['owner_user_id'] === (int) $currentUser['user_id'];
                $currentRule = $hasAccess ? fc_challenge_rule_current_published($pdo, $challengeId) : null;

                if ($participation !== null && (string) $participation['participation_status'] === 'ACTIVE') {
                    $participantLabel = 'Active participant';
                } elseif ($participation !== null && (string) $participation['participation_status'] === 'WITHDRAWN') {
                    $participantLabel = 'Withdrawn';
                } elseif ($challengeOwner) {
                    $participantLabel = 'Owner · Not competing';
                } elseif ($hasAccess) {
                    $participantLabel = 'Challenge access';
                } else {
                    $participantLabel = 'Not participating';
                }

                $rulesLabel = !$hasAccess
                    ? 'Join to view'
                    : ($currentRule !== null ? 'Published v' . (string) $currentRule['version_number'] : 'Not published');
                ?>
                <article class="overview-challenge-card">
                    <div class="overview-challenge-title">
                        <h3><?= fc_e((string) $crewChallenge['display_name']) ?></h3>
                        <p><?= fc_e((string) $crew['display_name']) ?> · <?= fc_e((string) $crewChallenge['participant_count']) ?> active <?= (int) $crewChallenge['participant_count'] === 1 ? 'participant' : 'participants' ?></p>
                    </div>

                    <div class="overview-challenge-facts" aria-label="Challenge status">
                        <div>
                            <span>Lifecycle</span>
                            <strong><?= fc_e(fc_challenge_lifecycle_label((string) $crewChallenge['lifecycle_status'], (string) $crewChallenge['operational_state'])) ?></strong>
                        </div>
                        <div>
                            <span>Your status</span>
                            <strong><?= fc_e($participantLabel) ?></strong>
                        </div>
                        <div>
                            <span>Rules</span>
                            <strong><?= fc_e($rulesLabel) ?></strong>
                        </div>
                    </div>

                    <div class="overview-challenge-action">
                        <?php if ($hasAccess): ?>
                            <form method="post" action="/challenge.php">
                                <?= fc_csrf_input() ?>
                                <button class="button button-primary" type="submit" name="select_challenge" value="<?= fc_e((string) $crewChallenge['public_id']) ?>">Open Challenge</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="/challenge.php">
                                <?= fc_csrf_input() ?>
                                <input type="hidden" name="action" value="join_challenge">
                                <input type="hidden" name="challenge_public_id" value="<?= fc_e((string) $crewChallenge['public_id']) ?>">
                                <button class="button button-primary" type="submit">Review and Join</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
