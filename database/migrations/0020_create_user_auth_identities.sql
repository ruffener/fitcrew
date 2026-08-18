CREATE TABLE user_auth_identities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    provider_key VARCHAR(20) NOT NULL,
    issuer VARCHAR(255) NOT NULL,
    provider_subject VARCHAR(255) NULL,
    provider_tenant_id VARCHAR(64) NULL,
    provider_object_id VARCHAR(255) NULL,
    protocol_subject VARCHAR(255) NULL,
    email_at_provider VARCHAR(320) NULL,
    email_verified_at_provider DATETIME(6) NULL,
    identity_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    linked_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_authenticated_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    unlinked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_auth_identities_user (user_id),
    KEY idx_auth_identities_provider_status (provider_key, identity_status),
    UNIQUE KEY uq_auth_identity_oidc_subject (provider_key, issuer, provider_subject),
    UNIQUE KEY uq_auth_identity_ms_object (provider_key, provider_tenant_id, provider_object_id),
    CONSTRAINT fk_auth_identity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_auth_identity_provider CHECK (provider_key IN ('GOOGLE', 'APPLE', 'MICROSOFT')),
    CONSTRAINT chk_auth_identity_status CHECK (identity_status IN ('ACTIVE', 'REVOKED', 'UNLINKED', 'DISABLED')),
    CONSTRAINT chk_auth_identity_shape CHECK (
        (provider_key IN ('GOOGLE', 'APPLE')
            AND provider_subject IS NOT NULL
            AND provider_tenant_id IS NULL
            AND provider_object_id IS NULL)
        OR
        (provider_key = 'MICROSOFT'
            AND provider_tenant_id IS NOT NULL
            AND provider_object_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
