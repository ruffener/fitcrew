<?php

declare(strict_types=1);

/**
 * Internal account resolver. Call ONLY after validating a current mailbox proof.
 * Not an HTTP API and not authorization based on an email string alone.
 * The caller owns a transaction and authorizes new-account admission explicitly.
 * @return array{user:array,identity:array,new_account:bool}
 */
function fc_email_account_resolve_verified_mailbox(
    PDO $pdo, string $emailSubject, ?callable $admitNewAccount = null, ?string $displayName = null, string $proofSource = 'EMAIL_MAGIC_LINK'
): array {
    if (!$pdo->inTransaction()) throw new LogicException('Mailbox resolution requires a transaction.');
    $emailSubject = fc_email_magic_link_subject($emailSubject);
    $identity = fc_auth_identity_find_oidc(
        $pdo,
        'EMAIL',
        FC_EMAIL_MAGIC_LINK_ISSUER,
        $emailSubject,
        true
    );
    $newAccount = false;
    $verifiedOwner = fc_contact_email_find_verified_owner($pdo, $emailSubject, true);

    if ($identity !== null || $verifiedOwner !== null) {
        if ($identity !== null && $verifiedOwner !== null
            && (int) $verifiedOwner['user_id'] !== (int) $identity['user_id']) {
            throw new DomainException('account_reconciliation_required');
        }
        if ($identity !== null && (string) $identity['identity_status'] !== 'ACTIVE') {
            throw new DomainException('fitcrew_account_access_denied');
        }
        // The completed mailbox proof may use the unique canonical VERIFIED
        // owner. A descriptive provider email alone never selects an account.
        $userId = (int) ($identity['user_id'] ?? $verifiedOwner['user_id']);
        $user = fc_user_find_by_id($pdo, $userId, true);
        if ($user === null || (string) $user['account_status'] !== 'ACTIVE') {
            throw new DomainException('fitcrew_account_access_denied');
        }
    } else {
        if (fc_auth_identity_email_evidence_owners($pdo, $emailSubject) !== []) {
            throw new DomainException('account_reconciliation_required');
        }
        if ($admitNewAccount === null) {
            throw new DomainException('prelaunch_new_account_denied');
        }
        $admitNewAccount();
        $created = fc_user_create($pdo, $displayName, 'ACTIVE', 'USER');
        $user = fc_user_find_by_id($pdo, (int) $created['id'], true);
        if ($user === null) {
            throw new RuntimeException('Unable to load newly created FitCrew user.');
        }
        $newAccount = true;
        fc_audit_event_write($pdo, [
            'actor_user_id' => (int) $user['id'],
            'event_type' => 'ACCOUNT_CREATED_EMAIL',
            'target_type' => 'USER',
            'target_id' => (string) $user['public_id'],
            'outcome' => 'SUCCESS',
            'metadata' => ['provider' => 'EMAIL'],
        ]);
    }

    if ($identity === null) {
        $createdIdentity = fc_auth_identity_create($pdo, (int) $user['id'], [
            'provider_key' => 'EMAIL',
            'issuer' => FC_EMAIL_MAGIC_LINK_ISSUER,
            'provider_subject' => $emailSubject,
            'email_at_provider' => $emailSubject,
            'provider_email_verified' => 1,
            'email_verification_observed_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'identity_status' => 'ACTIVE',
        ]);
        $identity = fc_auth_identity_find_oidc(
            $pdo, 'EMAIL', FC_EMAIL_MAGIC_LINK_ISSUER, $emailSubject, true
        );
        if ($identity === null || (int) $identity['id'] !== (int) $createdIdentity['id']) {
            throw new RuntimeException('Unable to load newly created EMAIL identity.');
        }
    }

    fc_auth_identity_update_provider_claims($pdo, (int) $identity['id'], [
        'email_at_provider' => $emailSubject,
        'provider_email_verified' => 1,
    ]);
    fc_contact_email_ensure_verified($pdo, (int) $user['id'], $emailSubject, $proofSource);

    return ['user' => $user, 'identity' => $identity, 'new_account' => $newAccount];
}
