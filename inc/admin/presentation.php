<?php
declare(strict_types=1);

function fc_admin_role_label(?string $role): string
{
    return ['USER' => 'User', 'PLATFORM_ADMIN' => 'Admin', 'PLATFORM_SUPER_ADMIN' => 'Super Admin'][$role ?? ''] ?? '—';
}

function fc_admin_text(mixed $value): string
{
    return fc_e($value === null || $value === '' ? '—' : (string) $value);
}

/** Render only caller-selected fields. Links are assembled from fixed routes and escaped public IDs. */
function fc_admin_table(array $rows, array $columns, ?array $link = null): void
{
    if ($rows === []) { echo '<p class="admin-empty">No records found.</p>'; return; }
    echo '<div class="admin-table-wrap" tabindex="0" role="region" aria-label="Scrollable records"><table class="admin-table"><thead><tr>';
    foreach ($columns as $label) { echo '<th scope="col">' . fc_e($label) . '</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($columns as $key => $label) {
            $value = $key === 'platform_role_code' || $key === 'old_role' || $key === 'new_role'
                ? fc_admin_role_label($row[$key] ?? null) : ($row[$key] ?? null);
            if ($key === 'provider_email_verified') { $value = $value === null ? 'Unknown' : ((int) $value === 1 ? 'Yes' : 'No'); }
            if ($key === 'is_primary_for_contact') { $value = (int) $value === 1 ? 'Yes' : 'No'; }
            echo '<td>';
            if ($key === 'actor_name' || $key === 'target_name') {
                echo fc_admin_audit_person($row, $key === 'actor_name' ? 'actor' : 'target');
            } elseif ($link !== null && $key === $link['column']) {
                echo '<a href="' . fc_e($link['route'] . '?id=' . rawurlencode((string) $row[$link['id'] ?? 'public_id'])) . '">' . fc_admin_text($value) . '</a>';
            } else { echo fc_admin_text($value); }
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function fc_admin_facts(array $facts): void
{
    echo '<dl class="admin-facts">';
    foreach ($facts as $label => $value) { echo '<div><dt>' . fc_e($label) . '</dt><dd>' . fc_admin_text($value) . '</dd></div>'; }
    echo '</dl>';
}

/** Current contact information is display context, never authorization or historical email evidence. */
function fc_admin_audit_person(array $row, string $prefix): string
{
    $name = fc_admin_text($row[$prefix . '_name'] ?? null);
    $email = (string) ($row[$prefix . '_email'] ?? '');
    $publicId = (string) ($row[$prefix . '_public_id'] ?? '');
    $detail = $email !== '' ? $email : ($publicId !== '' ? 'ID: ' . $publicId : '');
    return $name . ($detail === '' ? '' : '<span class="admin-audit-contact">' . fc_e($detail) . '</span>');
}

function fc_admin_audit_table(array $rows): void
{
    if ($rows !== []) { echo '<p class="admin-time-note">Email addresses show current primary contacts.</p>'; }
    fc_admin_table($rows, ['occurred_at' => 'Time (UTC)', 'actor_name' => 'Actor', 'event_type' => 'Action',
        'target_name' => 'Target', 'old_role' => 'Old role', 'new_role' => 'New role', 'outcome' => 'Result']);
}

function fc_admin_search(string $query, string $label): void
{
    echo '<form class="admin-search" method="get"><label for="admin-search">' . fc_e($label) . '</label>'
        . '<div><input id="admin-search" type="search" name="q" maxlength="120" value="' . fc_e($query)
        . '"><button class="button button-primary" type="submit">Search</button></div></form>';
}

function fc_admin_pager(int $page, bool $more, string $query): void
{
    echo '<nav class="admin-pager" aria-label="Results pages">';
    if ($page > 1) { echo '<a href="?' . fc_e(http_build_query(['q' => $query, 'page' => $page-1])) . '">Previous</a>'; }
    echo '<span>Page ' . $page . ' · Up to 50 records per page</span>';
    if ($more && $page < 10000) { echo '<a href="?' . fc_e(http_build_query(['q' => $query, 'page' => $page+1])) . '">Next</a>'; }
    echo '</nav>';
}

function fc_admin_input(array $input, string $key, int $limit = 120): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || strlen($value) > $limit) { throw new FcAdminDenied('invalid_input', 400); }
    return trim($value);
}

function fc_admin_id(string $id): string
{
    if (!preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id)) { throw new FcAdminDenied('invalid_input', 400); }
    return $id;
}
