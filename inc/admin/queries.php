<?php
declare(strict_types=1);

function fc_admin_rows(PDO $pdo, string $sql, array $args = []): array
{
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function fc_admin_one(PDO $pdo, string $sql, array $args = []): ?array
{
    return fc_admin_rows($pdo, $sql, $args)[0] ?? null;
}

function fc_admin_like(string $query): string
{
    return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
}

function fc_admin_list(PDO $pdo, string $kind, string $query, int $page): array
{
    $offset = (max(1, min(10000, $page)) - 1) * 50;
    $like = fc_admin_like($query);
    if ($kind === 'users' || $kind === 'admins') {
        $where = $kind === 'admins' && $query === '' ? "u.platform_role_code IN ('PLATFORM_ADMIN','PLATFORM_SUPER_ADMIN')" : '1=1';
        return fc_admin_rows($pdo, "SELECT u.public_id, u.display_name, u.account_status, u.platform_role_code, u.created_at,
            (SELECT e.email FROM user_contact_emails e WHERE e.user_id=u.id AND e.removed_at IS NULL AND e.is_primary_for_contact=1 LIMIT 1) AS contact_email
            FROM users u WHERE $where AND (u.display_name LIKE ? ESCAPE '!' OR u.public_id LIKE ? ESCAPE '!'
            OR EXISTS (SELECT 1 FROM user_contact_emails e WHERE e.user_id=u.id AND e.removed_at IS NULL AND e.email LIKE ? ESCAPE '!')
            OR EXISTS (SELECT 1 FROM user_auth_identities i WHERE i.user_id=u.id AND i.email_at_provider LIKE ? ESCAPE '!'))
            ORDER BY u.id DESC LIMIT 51 OFFSET $offset", [$like, $like, $like, $like]);
    }
    if ($kind === 'crews') {
        return fc_admin_rows($pdo, "SELECT c.public_id, c.display_name, c.crew_status, c.created_at, u.display_name AS owner_name,
            (SELECT COUNT(*) FROM crew_memberships m WHERE m.crew_id=c.id AND m.membership_status='ACTIVE') AS active_members
            FROM crews c JOIN users u ON u.id=c.owner_user_id
            WHERE c.display_name LIKE ? ESCAPE '!' OR c.public_id LIKE ? ESCAPE '!' OR u.display_name LIKE ? ESCAPE '!'
            ORDER BY c.id DESC LIMIT 51 OFFSET $offset", [$like, $like, $like]);
    }
    if ($kind === 'invitations') {
        return fc_admin_rows($pdo, "SELECT i.public_id, i.invited_email, i.invitation_status, i.transport_status, i.expires_at,
            c.display_name AS crew_name, ch.display_name AS challenge_name,
            CASE WHEN i.challenge_id IS NULL THEN 'Legacy Crew-only' ELSE 'Challenge' END AS invitation_scope,
            CASE WHEN i.invitation_status='PENDING' AND i.expires_at<=CURRENT_TIMESTAMP(6) THEN 'EXPIRED' ELSE i.invitation_status END AS effective_status
            FROM crew_invitations i JOIN crews c ON c.id=i.crew_id
            LEFT JOIN challenges ch ON ch.id=i.challenge_id AND ch.crew_id=i.crew_id
            WHERE i.invited_email LIKE ? ESCAPE '!' OR i.public_id LIKE ? ESCAPE '!' OR c.display_name LIKE ? ESCAPE '!'
            OR ch.display_name LIKE ? ESCAPE '!'
            ORDER BY i.id DESC LIMIT 51 OFFSET $offset", [$like, $like, $like, $like]);
    }
    throw new LogicException('Unknown Admin list.');
}

function fc_admin_user(PDO $pdo, string $publicId): ?array
{
    return fc_admin_one($pdo, 'SELECT id, public_id, display_name, account_status, platform_role_code, timezone, locale,
        created_at, updated_at, onboarding_completed_at, suspended_at, deactivated_at FROM users WHERE public_id=?', [$publicId]);
}

function fc_admin_user_details(PDO $pdo, array $user): array
{
    $id = (int) $user['id'];
    return [
        'contacts' => fc_admin_rows($pdo, 'SELECT email, verification_status, is_primary_for_contact, verified_at
            FROM user_contact_emails WHERE user_id=? AND removed_at IS NULL ORDER BY is_primary_for_contact DESC, id', [$id]),
        'identities' => fc_admin_rows($pdo, 'SELECT provider_key, identity_status, provider_email_verified, linked_at, last_authenticated_at
            FROM user_auth_identities WHERE user_id=? ORDER BY id', [$id]),
        'sessions' => fc_admin_rows($pdo, "SELECT i.provider_key, s.created_at, s.last_seen_at, s.idle_expires_at, s.absolute_expires_at,
            s.revoked_at, CASE WHEN s.revoked_at IS NOT NULL THEN 'REVOKED'
            WHEN s.idle_expires_at<=CURRENT_TIMESTAMP(6) OR s.absolute_expires_at<=CURRENT_TIMESTAMP(6) THEN 'EXPIRED'
            WHEN u.account_status<>'ACTIVE' OR i.identity_status<>'ACTIVE' THEN 'INACTIVE' ELSE 'ACTIVE' END AS session_status
            FROM user_sessions s JOIN users u ON u.id=s.user_id
            JOIN user_auth_identities i ON i.id=s.auth_identity_id AND i.user_id=s.user_id
            WHERE s.user_id=? ORDER BY s.id DESC LIMIT 50", [$id]),
        'memberships' => fc_admin_rows($pdo, 'SELECT c.public_id, c.display_name, m.role_code, m.membership_status, m.joined_at
            FROM crew_memberships m JOIN crews c ON c.id=m.crew_id WHERE m.user_id=? ORDER BY m.id DESC LIMIT 50', [$id]),
        'audit' => fc_admin_audit_rows($pdo, 'user', $id),
    ];
}

function fc_admin_crew(PDO $pdo, string $publicId): ?array
{
    return fc_admin_one($pdo, 'SELECT c.id, c.public_id, c.display_name, c.description, c.crew_status, c.created_at,
        c.archived_at, u.public_id AS owner_public_id, u.display_name AS owner_name
        FROM crews c JOIN users u ON u.id=c.owner_user_id WHERE c.public_id=?', [$publicId]);
}

function fc_admin_crew_details(PDO $pdo, array $crew): array
{
    $id = (int) $crew['id'];
    return [
        'members' => fc_admin_rows($pdo, 'SELECT u.public_id, u.display_name, m.role_code, m.membership_status, m.joined_at
            FROM crew_memberships m JOIN users u ON u.id=m.user_id WHERE m.crew_id=? ORDER BY m.id DESC LIMIT 50', [$id]),
        'challenges' => fc_admin_rows($pdo, "SELECT c.public_id, c.display_name, c.lifecycle_status, c.operational_state, c.created_at,
            (SELECT COUNT(*) FROM challenge_participations p WHERE p.challenge_id=c.id AND p.participation_status='ACTIVE') AS active_participants
            FROM challenges c WHERE c.crew_id=? ORDER BY c.id DESC LIMIT 50", [$id]),
        'invitations' => fc_admin_rows($pdo, "SELECT i.public_id, i.invited_email, i.invitation_status, i.transport_status, i.expires_at,
            ch.display_name AS challenge_name,
            CASE WHEN i.challenge_id IS NULL THEN 'Legacy Crew-only' ELSE 'Challenge' END AS invitation_scope
            FROM crew_invitations i LEFT JOIN challenges ch ON ch.id=i.challenge_id AND ch.crew_id=i.crew_id
            WHERE i.crew_id=? ORDER BY i.id DESC LIMIT 50", [$id]),
        'audit' => fc_admin_audit_rows($pdo, 'crew', $id),
    ];
}

function fc_admin_invitation(PDO $pdo, string $publicId): ?array
{
    return fc_admin_one($pdo, "SELECT i.id, i.public_id, i.invited_email, i.invitation_status, i.transport_status,
        i.transport_driver, i.transport_attempted_at, i.sent_at, i.resend_count, i.expires_at, i.accepted_at, i.cancelled_at, i.created_at,
        c.public_id AS crew_public_id, c.display_name AS crew_name, u.display_name AS inviter_name,
        ch.public_id AS challenge_public_id, ch.display_name AS challenge_name, ch.lifecycle_status AS challenge_lifecycle,
        CASE WHEN i.challenge_id IS NULL THEN 'Legacy Crew-only' ELSE 'Challenge' END AS invitation_scope,
        a.display_name AS acceptor_name,
        CASE WHEN i.invitation_status='PENDING' AND i.expires_at<=CURRENT_TIMESTAMP(6) THEN 'EXPIRED' ELSE i.invitation_status END AS effective_status
        FROM crew_invitations i JOIN crews c ON c.id=i.crew_id JOIN users u ON u.id=i.invited_by_user_id
        LEFT JOIN challenges ch ON ch.id=i.challenge_id AND ch.crew_id=i.crew_id
        LEFT JOIN users a ON a.id=i.accepted_by_user_id WHERE i.public_id=?", [$publicId]);
}

/** No raw audit metadata. Project only the two governed role values for Admin role events. */
function fc_admin_audit_rows(PDO $pdo, string $scope = 'all', int|string $id = 0): array
{
    $args = [];
    $where = '1=1';
    if ($scope === 'user') {
        $where = "(a.actor_user_id=? OR (a.target_type='USER' AND a.target_id=?))";
        $args = [$id, (string) $id];
    } elseif ($scope === 'crew') {
        $where = "(a.group_id=? OR (a.target_type='CREW' AND BINARY a.target_id=BINARY (SELECT public_id FROM crews WHERE id=?)))";
        $args = [(int) $id, (int) $id];
    } elseif ($scope === 'invitation') {
        $where = "a.target_type='CREW_INVITATION' AND a.target_id=?"; $args = [(string) $id];
    }
    return fc_admin_rows($pdo, "SELECT a.occurred_at, a.event_type, a.outcome, u.display_name AS actor_name,
        t.display_name AS target_name,
        CASE WHEN a.event_type IN ('ADMIN_ROLE_GRANTED','ADMIN_ROLE_REVOKED','ADMIN_SUPER_BOOTSTRAPPED')
            THEN JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.old_role')) ELSE NULL END AS old_role,
        CASE WHEN a.event_type IN ('ADMIN_ROLE_GRANTED','ADMIN_ROLE_REVOKED','ADMIN_SUPER_BOOTSTRAPPED')
            THEN JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.new_role')) ELSE NULL END AS new_role
        FROM audit_events a LEFT JOIN users u ON u.id=a.actor_user_id
        LEFT JOIN users t ON a.target_type='USER' AND BINARY a.target_id=BINARY CAST(t.id AS CHAR)
        WHERE $where ORDER BY a.id DESC LIMIT 50", $args);
}

function fc_admin_dashboard(PDO $pdo): array
{
    return fc_admin_one($pdo, "SELECT
        (SELECT COUNT(*) FROM users) AS users,
        (SELECT COUNT(*) FROM users WHERE account_status='ACTIVE') AS active_users,
        (SELECT COUNT(*) FROM crews) AS crews,
        (SELECT COUNT(*) FROM challenges) AS challenges,
        (SELECT COUNT(*) FROM crew_invitations WHERE invitation_status='PENDING' AND expires_at>CURRENT_TIMESTAMP(6)) AS pending_invitations,
        (SELECT COUNT(*) FROM crew_invitations WHERE transport_status='TRANSPORT_FAILED') AS failed_transport") ?? [];
}

function fc_admin_authentication(PDO $pdo): array
{
    return [
        'providers' => fc_admin_rows($pdo, 'SELECT provider_key, identity_status, COUNT(*) AS total
            FROM user_auth_identities GROUP BY provider_key, identity_status ORDER BY provider_key, identity_status'),
        'sessions' => fc_admin_rows($pdo, "SELECT CASE WHEN s.revoked_at IS NOT NULL THEN 'REVOKED'
            WHEN s.idle_expires_at<=CURRENT_TIMESTAMP(6) OR s.absolute_expires_at<=CURRENT_TIMESTAMP(6) THEN 'EXPIRED'
            WHEN u.account_status<>'ACTIVE' OR i.identity_status<>'ACTIVE' THEN 'INACTIVE' ELSE 'ACTIVE' END AS session_status, COUNT(*) AS total
            FROM user_sessions s JOIN users u ON u.id=s.user_id
            JOIN user_auth_identities i ON i.id=s.auth_identity_id AND i.user_id=s.user_id GROUP BY session_status"),
        'events' => fc_admin_rows($pdo, "SELECT event_type, outcome, COUNT(*) AS total FROM audit_events
            WHERE occurred_at>=CURRENT_TIMESTAMP(6)-INTERVAL 1 DAY
            AND (event_type LIKE '%AUTH%' OR event_type LIKE '%SESSION%' OR event_type LIKE 'EMAIL_MAGIC_LINK%')
            GROUP BY event_type, outcome ORDER BY total DESC LIMIT 50"),
    ];
}

function fc_admin_system(PDO $pdo): array
{
    $s = fc_admin_one($pdo, "SELECT COUNT(*) AS total FROM schema_migrations WHERE migration='0400_platform_super_admin.sql'");
    $invariant = fc_admin_one($pdo, "SELECT COUNT(*) AS total FROM information_schema.statistics
        WHERE table_schema=DATABASE() AND table_name='users' AND index_name='uq_users_single_platform_super_admin' AND non_unique=0");
    return ['Database connection' => 'Available', 'Admin migration' => (int) $s['total'] === 1 ? 'Applied' : 'Pending',
        'Super Admin unique index' => (int) $invariant['total'] === 1 ? 'Present' : 'Missing',
        'Super Admin accounts' => (string) $pdo->query("SELECT COUNT(*) FROM users WHERE platform_role_code='PLATFORM_SUPER_ADMIN'")->fetchColumn(),
        'Database clock (UTC)' => (string) $pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn(),
        'Console mode' => 'Read-only operations; Super Admin can manage Admin roles'];
}
