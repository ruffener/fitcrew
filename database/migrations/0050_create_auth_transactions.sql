CREATE TABLE auth_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    intent VARCHAR(24) NOT NULL,
    expected_provider VARCHAR(20) NOT NULL,
    expected_user_id BIGINT UNSIGNED NULL,
    state_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    nonce_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    pkce_verifier_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    browser_session_binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    post_auth_destination_key VARCHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_transactions_public_id (public_id),
    UNIQUE KEY uq_auth_transactions_state_hash (state_hash),
    KEY idx_auth_transactions_expiry (expires_at, consumed_at),
    KEY idx_auth_transactions_user (expected_user_id),
    CONSTRAINT fk_auth_transaction_user FOREIGN KEY (expected_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_auth_transaction_intent CHECK (intent IN ('LOGIN', 'LINK_IDENTITY', 'REAUTHENTICATE')),
    CONSTRAINT chk_auth_transaction_provider CHECK (expected_provider IN ('GOOGLE', 'APPLE', 'MICROSOFT')),
    CONSTRAINT chk_auth_transaction_user_scope CHECK (
        (intent = 'LOGIN' AND expected_user_id IS NULL)
        OR (intent IN ('LINK_IDENTITY', 'REAUTHENTICATE') AND expected_user_id IS NOT NULL)
    ),
    CONSTRAINT chk_auth_transaction_expiry CHECK (expires_at > created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
