-- Identity/Auth-owned ADMIN-2A durability. Existing identity and audit history are untouched.
CREATE TABLE auth_user_operations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    target_user_id BIGINT UNSIGNED NOT NULL,
    request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation_key VARCHAR(32) NOT NULL,
    result_json LONGTEXT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_auth_user_operation_request (actor_user_id, request_key),
    CONSTRAINT fk_user_operation_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_user_operation_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_user_operation_result CHECK (JSON_VALID(result_json)),
    CONSTRAINT chk_user_operation_key CHECK (operation_key IN
        ('profile','end_sessions','suspend','restore','primary_contact','replacement_contact'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_contact_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    target_user_id BIGINT UNSIGNED NOT NULL,
    email_canonical VARCHAR(254) NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(500) NOT NULL,
    target_updated_at DATETIME(6) NOT NULL,
    target_role VARCHAR(32) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'PENDING_SEND',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    UNIQUE KEY uq_contact_verification_public (public_id),
    UNIQUE KEY uq_contact_verification_hash (token_hash),
    KEY idx_contact_verification_target (target_user_id, created_at),
    CONSTRAINT fk_contact_verification_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_contact_verification_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_contact_verification_role CHECK (target_role IN ('USER','PLATFORM_ADMIN')),
    CONSTRAINT chk_contact_verification_expiry CHECK
        (expires_at>created_at AND TIMESTAMPDIFF(SECOND,created_at,expires_at)<=900),
    CONSTRAINT chk_contact_verification_status CHECK
        (status IN ('PENDING_SEND','ISSUED','DELIVERY_FAILED','REPLACED','CONSUMED')),
    CONSTRAINT chk_contact_verification_consumed CHECK
        ((status='CONSUMED' AND consumed_at IS NOT NULL) OR (status<>'CONSUMED' AND consumed_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
