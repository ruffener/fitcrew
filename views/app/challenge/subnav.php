<nav class="challenge-subnav" aria-label="Challenge sections">
    <a class="<?= ($challengeSection ?? '') === 'home' ? 'is-current' : '' ?>" href="/challenge.php">Home</a>
    <a class="<?= ($challengeSection ?? '') === 'participants' ? 'is-current' : '' ?>" href="/participants.php">Participants</a>
    <span class="is-unavailable" aria-disabled="true">Standings <small>Unavailable</small></span>
    <span class="is-unavailable" aria-disabled="true">History <small>Unavailable</small></span>
    <a class="<?= ($challengeSection ?? '') === 'rules' ? 'is-current' : '' ?>" href="/rules.php">Rules</a>
</nav>
