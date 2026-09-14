-- Auth-owned EMAIL_MAGIC_LINK_V1 foundation.
-- Raw magic-link tokens are never stored. EMAIL identity remains distinct from
-- federated-provider email claims and from Website Crew invitation state.

ALTER TABLE user_auth_identities
    DROP CONSTRAINT chk_auth_identity_provider,
    DROP CONSTRAINT chk_auth_identity_shape,
    ADD CONSTRAINT chk_auth_identity_provider
        CHECK (provider_key IN ('GOOGLE', 'APPLE', 'MICROSOFT', 'EMAIL')),
    ADD CONSTRAINT chk_auth_identity_shape CHECK (
        (provider_key IN ('GOOGLE', 'APPLE', 'EMAIL')
            AND provider_subject IS NOT NULL
            AND provider_tenant_id IS NULL
            AND provider_object_id IS NULL)
        OR
        (provider_key = 'MICROSOFT'
            AND provider_tenant_id IS NOT NULL
            AND provider_object_id IS NOT NULL)
    );

ALTER TABLE auth_transactions
    DROP CONSTRAINT chk_auth_transaction_provider,
    ADD CONSTRAINT chk_auth_transaction_provider
        CHECK (expected_provider IN ('GOOGLE', 'APPLE', 'MICROSOFT', 'EMAIL'));

CREATE TABLE email_magic_link_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    auth_transaction_id BIGINT UNSIGNED NOT NULL,
    issuer VARCHAR(255) NOT NULL,
    email_subject VARCHAR(255) NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    flow_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    challenge_status VARCHAR(16) NOT NULL DEFAULT 'ISSUED',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    replaced_at DATETIME(6) NULL,
    -- Nullable uniqueness key maintained with the lifecycle transition. MariaDB
    -- 10.6 does not permit the equivalent CASE expression as a generated column.
    active_flow_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_magic_link_public (public_id),
    UNIQUE KEY uq_email_magic_link_token_hash (token_hash),
    UNIQUE KEY uq_email_magic_link_active_flow (active_flow_key_hash),
    KEY idx_email_magic_link_transaction (auth_transaction_id, challenge_status),
    KEY idx_email_magic_link_expiry (challenge_status, expires_at),
    CONSTRAINT fk_email_magic_link_transaction
        FOREIGN KEY (auth_transaction_id) REFERENCES auth_transactions(id) ON DELETE RESTRICT,
    CONSTRAINT chk_email_magic_link_issuer
        CHECK (issuer = 'https://fitcrewchallenge.com/auth/email'),
    CONSTRAINT chk_email_magic_link_status
        CHECK (challenge_status IN ('ISSUED', 'CONSUMED', 'REPLACED')),
    CONSTRAINT chk_email_magic_link_expiry
        CHECK (expires_at > created_at AND TIMESTAMPDIFF(SECOND, created_at, expires_at) <= 900),
    CONSTRAINT chk_email_magic_link_shape CHECK (
        (challenge_status = 'ISSUED'
            AND consumed_at IS NULL
            AND replaced_at IS NULL
            AND active_flow_key_hash = flow_key_hash)
        OR
        (challenge_status = 'CONSUMED'
            AND consumed_at IS NOT NULL
            AND replaced_at IS NULL
            AND active_flow_key_hash IS NULL)
        OR
        (challenge_status = 'REPLACED'
            AND consumed_at IS NULL
            AND replaced_at IS NOT NULL
            AND active_flow_key_hash IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
