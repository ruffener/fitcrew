<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/identity/contact_emails.php';

/**
 * Private recipient-email customization only; never authentication or admission.
 * UNKNOWN means verified ownership was not established, not that no account exists.
 * Do not expose this result to an inviter or through a public endpoint.
 */
function fc_auth_account_presence_for_email(PDO $pdo, string $email): string
{
    try {
        $owner = fc_contact_email_find_verified_owner($pdo, $email);
    } catch (InvalidArgumentException | PDOException) {
        return 'UNKNOWN';
    }

    return $owner === null ? 'UNKNOWN' : 'KNOWN_ACCOUNT';
}
