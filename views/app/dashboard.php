<section class="app-page-heading">
    <div>
        <p class="eyebrow">Authenticated FitCrew Challenge</p>
        <h1>Welcome<?= !empty($currentUser['display_name']) ? ', ' . fc_e((string) $currentUser['display_name']) : '' ?>.</h1>
        <p>Your FitCrew Challenge identity is authenticated. Group, challenge, scoring, and health-data behavior remain separately governed future work.</p>
        <div class="authenticated-summary">
            <strong>Signed in with <?= fc_e((string) ($currentUser['provider_key'] ?? 'provider')) ?></strong>
            <span>Account: <?= fc_e((string) ($currentUser['public_id'] ?? '')) ?></span>
        </div>
    </div>
    <span class="status-chip status-chip-neutral"><span aria-hidden="true">●</span> Signed in</span>
</section>

<section class="app-context-card" aria-label="Future group and challenge context">
    <div>
        <span class="context-kicker">Crew context</span>
        <strong>No group selected</strong>
        <p>Group behavior has not been implemented.</p>
    </div>
    <div class="context-divider" aria-hidden="true"></div>
    <div>
        <span class="context-kicker">Challenge context</span>
        <strong>No challenge selected</strong>
        <p>Challenge behavior has not been implemented.</p>
    </div>
</section>

<section class="app-card-grid">
    <article class="app-card">
        <span class="card-icon card-icon-blue" aria-hidden="true">●</span>
        <div>
            <p class="card-kicker">Groups</p>
            <h2>Your crew starts here.</h2>
            <p>Private group membership and roles are planned for the next authorized product foundation.</p>
        </div>
        <span class="status-chip status-chip-neutral"><span aria-hidden="true">○</span> Planned</span>
    </article>
    <article class="app-card">
        <span class="card-icon card-icon-orange" aria-hidden="true">▲</span>
        <div>
            <p class="card-kicker">Challenges</p>
            <h2>Shared goals, one clear truth.</h2>
            <p>Challenge configuration and scoring remain outside Phase 1B.</p>
        </div>
        <span class="status-chip status-chip-neutral"><span aria-hidden="true">○</span> Planned</span>
    </article>
    <article class="app-card">
        <span class="card-icon card-icon-navy" aria-hidden="true">♡</span>
        <div>
            <p class="card-kicker">Health connection</p>
            <h2>Provider connection comes later.</h2>
            <p>Health-data connection remains separately governed and is not part of FitCrew Challenge authentication.</p>
        </div>
        <a class="text-link" href="/health/google/status.php">View placeholder status →</a>
    </article>
</section>
