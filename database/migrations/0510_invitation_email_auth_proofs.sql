-- Auth proof only. Website continues to own invitation and enrollment state.
-- Existing invitation links are deliberately NOT backfilled as login proofs.
CREATE TABLE auth_invitation_email_proofs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    invitation_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    invitation_generation INT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    email_canonical VARCHAR(320) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    user_id BIGINT UNSIGNED NULL,
    session_record_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invitation_email_proof_public (public_id),
    UNIQUE KEY uq_invitation_email_proof_generation (invitation_public_id, invitation_generation),
    UNIQUE KEY uq_invitation_email_proof_token (token_hash),
    CONSTRAINT fk_invitation_email_proof_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_invitation_email_proof_session FOREIGN KEY (session_record_id) REFERENCES user_sessions(id) ON DELETE RESTRICT,
    CONSTRAINT chk_invitation_email_proof_expiry CHECK (expires_at > created_at),
    CONSTRAINT chk_invitation_email_proof_consumption CHECK (
        (consumed_at IS NULL AND user_id IS NULL AND session_record_id IS NULL)
        OR (consumed_at IS NOT NULL AND user_id IS NOT NULL AND session_record_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
