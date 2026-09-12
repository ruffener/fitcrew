-- Additive Family Alpha A-D foundation. No Auth, provider, evidence or scoring tables.
CREATE TABLE challenge_owner_controls (
    challenge_id BIGINT UNSIGNED NOT NULL,
    effective_end_at DATETIME(6) NULL,
    ended_by_user_id BIGINT UNSIGNED NULL,
    end_reason VARCHAR(500) NULL,
    archived_at DATETIME(6) NULL,
    archived_by_user_id BIGINT UNSIGNED NULL,
    deleted_at DATETIME(6) NULL,
    deleted_by_user_id BIGINT UNSIGNED NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (challenge_id),
    CONSTRAINT fk_coc_challenge FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE RESTRICT,
    CONSTRAINT fk_coc_end_actor FOREIGN KEY (ended_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_coc_archive_actor FOREIGN KEY (archived_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_coc_delete_actor FOREIGN KEY (deleted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE challenge_product_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    challenge_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    subject_user_id BIGINT UNSIGNED NULL,
    event_code VARCHAR(48) NOT NULL,
    details_json LONGTEXT NOT NULL,
    occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_cpe_challenge_time (challenge_id, id),
    KEY idx_cpe_subject (subject_user_id, challenge_id, id),
    CONSTRAINT fk_cpe_challenge FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpe_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpe_subject FOREIGN KEY (subject_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_cpe_json CHECK (JSON_VALID(details_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-app offers to known Crew members. These are NOT email tokens or Auth state.
CREATE TABLE challenge_participant_offers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    challenge_id BIGINT UNSIGNED NOT NULL,
    invited_user_id BIGINT UNSIGNED NOT NULL,
    invited_by_user_id BIGINT UNSIGNED NOT NULL,
    offer_status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
    offered_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    decided_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cpo_public (public_id),
    UNIQUE KEY uq_cpo_subject (challenge_id, invited_user_id),
    KEY idx_cpo_inbox (invited_user_id, offer_status),
    CONSTRAINT fk_cpo_challenge FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpo_invitee FOREIGN KEY (invited_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpo_inviter FOREIGN KEY (invited_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_cpo_status CHECK (offer_status IN ('PENDING','ACCEPTED','DECLINED','CANCELLED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE challenge_acceptance_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    challenge_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    rule_version_id BIGINT UNSIGNED NOT NULL,
    contract_code VARCHAR(64) NOT NULL,
    measurements_visibility VARCHAR(16) NOT NULL DEFAULT 'PRIVATE',
    progress_visibility VARCHAR(16) NOT NULL DEFAULT 'PRIVATE',
    accepted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_car_subject_time (challenge_id, user_id, id),
    CONSTRAINT fk_car_challenge FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE RESTRICT,
    CONSTRAINT fk_car_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_car_rule FOREIGN KEY (rule_version_id) REFERENCES challenge_rule_versions(id) ON DELETE RESTRICT,
    CONSTRAINT chk_car_measurements CHECK (measurements_visibility IN ('PRIVATE','CHALLENGE')),
    CONSTRAINT chk_car_progress CHECK (progress_visibility IN ('PRIVATE','CHALLENGE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE challenge_participation_intervals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    challenge_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    entered_at DATETIME(6) NOT NULL,
    exited_at DATETIME(6) NULL,
    exit_status VARCHAR(16) NULL,
    entry_source VARCHAR(24) NOT NULL,
    acceptance_record_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_cpi_subject (challenge_id, user_id, id),
    CONSTRAINT fk_cpi_challenge FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpi_acceptance FOREIGN KEY (acceptance_record_id) REFERENCES challenge_acceptance_records(id) ON DELETE RESTRICT,
    CONSTRAINT chk_cpi_source CHECK (entry_source IN ('LEGACY_SNAPSHOT','PERSONAL_ACCEPTANCE')),
    CONSTRAINT chk_cpi_exit CHECK (exit_status IS NULL OR exit_status IN ('WITHDRAWN','REMOVED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve only the interval supported by the existing snapshot. This DOES NOT
-- invent earlier intervals, acceptance, a rule version, or privacy consent.
INSERT INTO challenge_participation_intervals
    (challenge_id,user_id,entered_at,exited_at,exit_status,entry_source)
SELECT challenge_id,user_id,joined_at,
       CASE participation_status WHEN 'WITHDRAWN' THEN withdrawn_at WHEN 'REMOVED' THEN removed_at ELSE NULL END,
       CASE WHEN participation_status IN ('WITHDRAWN','REMOVED') THEN participation_status ELSE NULL END,
       'LEGACY_SNAPSHOT'
FROM challenge_participations;

CREATE TABLE challenge_privacy_preferences (
    challenge_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    measurements_visibility VARCHAR(16) NOT NULL DEFAULT 'PRIVATE',
    progress_visibility VARCHAR(16) NOT NULL DEFAULT 'PRIVATE',
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (challenge_id, user_id),
    CONSTRAINT fk_cpp_challenge FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_cpp_measurements CHECK (measurements_visibility IN ('PRIVATE','CHALLENGE')),
    CONSTRAINT chk_cpp_progress CHECK (progress_visibility IN ('PRIVATE','CHALLENGE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Website-owned Crew invitation state. Email is a delivery destination, not identity truth.
CREATE TABLE crew_invitations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    crew_id BIGINT UNSIGNED NOT NULL,
    invited_email VARCHAR(254) NOT NULL,
    invited_by_user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    invitation_status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
    expires_at DATETIME(6) NOT NULL,
    sent_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    resend_count INT UNSIGNED NOT NULL DEFAULT 0,
    accepted_by_user_id BIGINT UNSIGNED NULL,
    accepted_at DATETIME(6) NULL,
    cancelled_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_crew_invitation_public (public_id),
    UNIQUE KEY uq_crew_invitation_token (token_hash),
    KEY idx_crew_invitation_crew_status (crew_id, invitation_status, id),
    KEY idx_crew_invitation_email_status (invited_email, invitation_status, id),
    CONSTRAINT fk_crew_invitation_crew FOREIGN KEY (crew_id) REFERENCES crews(id) ON DELETE RESTRICT,
    CONSTRAINT fk_crew_invitation_inviter FOREIGN KEY (invited_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_crew_invitation_acceptor FOREIGN KEY (accepted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_crew_invitation_status CHECK (invitation_status IN ('PENDING','ACCEPTED','CANCELLED','EXPIRED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
