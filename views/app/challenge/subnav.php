<?php $challengeQuery = rawurlencode((string) ($challenge['public_id'] ?? '')); ?>
<nav class="challenge-subnav" aria-label="Challenge sections">
    <a class="<?= ($challengeSection ?? '') === 'home' ? 'is-current' : '' ?>" href="/challenge.php?view=detail&amp;challenge=<?= fc_e($challengeQuery) ?>">Home</a>
    <a class="<?= ($challengeSection ?? '') === 'participants' ? 'is-current' : '' ?>" href="/participants.php?challenge=<?= fc_e($challengeQuery) ?>">Participants</a>
    <span class="is-unavailable" aria-disabled="true">Standings <small>Unavailable</small></span>
    <span class="is-unavailable" aria-disabled="true">History <small>Unavailable</small></span>
    <a class="<?= ($challengeSection ?? '') === 'rules' ? 'is-current' : '' ?>" href="/rules.php?challenge=<?= fc_e($challengeQuery) ?>">Rules</a>
</nav>
