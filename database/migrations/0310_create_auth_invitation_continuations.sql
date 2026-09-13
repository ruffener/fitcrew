-- Auth-owned Family Alpha invitation continuation and generic rate-limit state.
-- No raw invitation token, invitation email, provider token, or Crew membership
-- decision is stored here.
CREATE TABLE auth_invitation_continuations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    purpose VARCHAR(40) NOT NULL,
    invitation_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    invitation_generation INT UNSIGNED NOT NULL,
    browser_session_binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    auth_transaction_id BIGINT UNSIGNED NULL,
    authenticated_user_id BIGINT UNSIGNED NULL,
    authenticated_session_id BIGINT UNSIGNED NULL,
    continuation_status VARCHAR(20) NOT NULL DEFAULT 'ISSUED',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    login_bound_at DATETIME(6) NULL,
    authenticated_at DATETIME(6) NULL,
    admission_consumed_at DATETIME(6) NULL,
    consumed_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_invitation_continuation_public (public_id),
    UNIQUE KEY uq_auth_invitation_continuation_transaction (auth_transaction_id),
    KEY idx_auth_invitation_continuation_browser (browser_session_binding_hash, continuation_status, expires_at),
    KEY idx_auth_invitation_continuation_invitation (invitation_public_id, invitation_generation),
    KEY idx_auth_invitation_continuation_user (authenticated_user_id, continuation_status),
    CONSTRAINT fk_auth_invitation_continuation_transaction
        FOREIGN KEY (auth_transaction_id) REFERENCES auth_transactions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_auth_invitation_continuation_user
        FOREIGN KEY (authenticated_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_auth_invitation_continuation_session
        FOREIGN KEY (authenticated_session_id) REFERENCES user_sessions(id) ON DELETE RESTRICT,
    CONSTRAINT chk_auth_invitation_continuation_purpose
        CHECK (purpose = 'CREW_INVITATION_ACCEPTANCE'),
    CONSTRAINT chk_auth_invitation_continuation_status
        CHECK (continuation_status IN ('ISSUED','LOGIN_BOUND','AUTHENTICATED','CONSUMED')),
    CONSTRAINT chk_auth_invitation_continuation_expiry
        CHECK (expires_at > created_at),
    CONSTRAINT chk_auth_invitation_continuation_shape CHECK (
        (continuation_status = 'ISSUED'
            AND auth_transaction_id IS NULL
            AND authenticated_user_id IS NULL
            AND authenticated_session_id IS NULL
            AND authenticated_at IS NULL
            AND consumed_at IS NULL)
        OR
        (continuation_status = 'LOGIN_BOUND'
            AND auth_transaction_id IS NOT NULL
            AND authenticated_user_id IS NULL
            AND authenticated_session_id IS NULL
            AND login_bound_at IS NOT NULL
            AND authenticated_at IS NULL
            AND consumed_at IS NULL)
        OR
        (continuation_status = 'AUTHENTICATED'
            AND authenticated_user_id IS NOT NULL
            AND authenticated_session_id IS NOT NULL
            AND authenticated_at IS NOT NULL
            AND consumed_at IS NULL)
        OR
        (continuation_status = 'CONSUMED'
            AND authenticated_user_id IS NOT NULL
            AND authenticated_session_id IS NOT NULL
            AND authenticated_at IS NOT NULL
            AND consumed_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One logical Website invitation may authorize at most one new FitCrew account,
-- even when multiple continuations or resend generations exist. The claim is
-- inserted before user creation in the same transaction, so rollback releases it.
CREATE TABLE auth_invitation_admission_claims (
    invitation_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    continuation_id BIGINT UNSIGNED NOT NULL,
    admitted_user_id BIGINT UNSIGNED NULL,
    claimed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (invitation_public_id),
    UNIQUE KEY uq_auth_invitation_admission_continuation (continuation_id),
    KEY idx_auth_invitation_admission_user (admitted_user_id),
    CONSTRAINT fk_auth_invitation_admission_continuation
        FOREIGN KEY (continuation_id) REFERENCES auth_invitation_continuations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_auth_invitation_admission_user
        FOREIGN KEY (admitted_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_auth_invitation_admission_completion CHECK (
        (admitted_user_id IS NULL AND completed_at IS NULL)
        OR
        (admitted_user_id IS NOT NULL AND completed_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic security buckets. Only keyed evidence is retained; callers' raw IP,
-- user, route, invitation, or other subject values are never stored.
CREATE TABLE security_rate_limit_buckets (
    bucket_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_started_at DATETIME(6) NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    blocked_until DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (bucket_key_hash),
    KEY idx_security_rate_limit_cleanup (updated_at, blocked_until),
    CONSTRAINT chk_security_rate_limit_attempts CHECK (attempt_count >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
