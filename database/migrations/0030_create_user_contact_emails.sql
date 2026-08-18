CREATE TABLE user_contact_emails (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(320) NOT NULL,
    email_canonical VARCHAR(320) NOT NULL,
    verification_status VARCHAR(20) NOT NULL DEFAULT 'UNVERIFIED',
    verified_at DATETIME(6) NULL,
    source_key VARCHAR(32) NOT NULL,
    is_primary_for_contact TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    removed_at DATETIME(6) NULL,
    active_email_canonical VARCHAR(320)
        GENERATED ALWAYS AS (CASE WHEN removed_at IS NULL THEN email_canonical ELSE NULL END) PERSISTENT,
    primary_active_user_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (CASE WHEN is_primary_for_contact = 1 AND removed_at IS NULL THEN user_id ELSE NULL END) PERSISTENT,
    PRIMARY KEY (id),
    KEY idx_contact_email_user (user_id),
    KEY idx_contact_email_canonical (email_canonical),
    UNIQUE KEY uq_contact_email_active_per_user (user_id, active_email_canonical),
    UNIQUE KEY uq_contact_email_one_primary (primary_active_user_id),
    CONSTRAINT fk_contact_email_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_contact_email_verification CHECK (verification_status IN ('UNVERIFIED', 'VERIFIED')),
    CONSTRAINT chk_contact_email_primary CHECK (is_primary_for_contact IN (0, 1)),
    CONSTRAINT chk_contact_email_verified_at CHECK (
        (verification_status = 'UNVERIFIED' AND verified_at IS NULL)
        OR (verification_status = 'VERIFIED' AND verified_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
