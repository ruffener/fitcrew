-- Website-owned Challenge invitation journey foundation.
-- Preserves one Crew -> many historical Challenges while enforcing at most one
-- current/non-finalized Challenge per Crew through an explicit database-backed
-- current-Challenge authority.

-- Fail closed before any persistent mutation if a Crew already has more than
-- one current/non-finalized Challenge under the accepted pre-0500 semantics.
CREATE TEMPORARY TABLE fc_current_challenge_preflight (
    crew_id BIGINT UNSIGNED NOT NULL,
    challenge_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (crew_id),
    UNIQUE KEY uq_fc_current_challenge_preflight_challenge (challenge_id)
) ENGINE=InnoDB;

INSERT INTO fc_current_challenge_preflight (crew_id, challenge_id)
SELECT c.crew_id, c.id
FROM challenges c
LEFT JOIN challenge_owner_controls ctl ON ctl.challenge_id = c.id
WHERE c.lifecycle_status <> 'COMPLETED'
  AND c.completed_at IS NULL
  AND (ctl.effective_end_at IS NULL)
  AND (ctl.archived_at IS NULL)
  AND (ctl.deleted_at IS NULL);

ALTER TABLE challenges
    ADD UNIQUE KEY uq_challenges_crew_id_id (crew_id, id);

CREATE TABLE crew_current_challenges (
    crew_id BIGINT UNSIGNED NOT NULL,
    challenge_id BIGINT UNSIGNED NOT NULL,
    established_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (crew_id),
    UNIQUE KEY uq_crew_current_challenge_challenge (challenge_id),
    CONSTRAINT fk_crew_current_challenge_crew
        FOREIGN KEY (crew_id) REFERENCES crews(id) ON DELETE RESTRICT,
    CONSTRAINT fk_crew_current_challenge_match
        FOREIGN KEY (crew_id, challenge_id) REFERENCES challenges(crew_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO crew_current_challenges (crew_id, challenge_id)
SELECT crew_id, challenge_id FROM fc_current_challenge_preflight;

DROP TEMPORARY TABLE fc_current_challenge_preflight;

-- Historical Crew-only invitation rows remain challenge_id = NULL. They are
-- never silently promoted into Challenge consent. All new normal invitations
-- created after this migration are Challenge-scoped by application contract.
ALTER TABLE crew_invitations
    ADD COLUMN challenge_id BIGINT UNSIGNED NULL AFTER crew_id,
    ADD KEY idx_crew_invitation_challenge_status (challenge_id, invitation_status, id),
    ADD UNIQUE KEY uq_crew_invitation_scope (id, crew_id, challenge_id),
    ADD CONSTRAINT fk_crew_invitation_challenge_match
        FOREIGN KEY (crew_id, challenge_id) REFERENCES challenges(crew_id, id) ON DELETE RESTRICT;

ALTER TABLE challenge_rule_versions
    ADD UNIQUE KEY uq_challenge_rule_version_scope (id, challenge_id);

CREATE TABLE challenge_invitation_acceptance_intents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    invitation_id BIGINT UNSIGNED NOT NULL,
    invitation_generation INT UNSIGNED NOT NULL,
    crew_id BIGINT UNSIGNED NOT NULL,
    challenge_id BIGINT UNSIGNED NOT NULL,
    rule_version_id BIGINT UNSIGNED NOT NULL,
    consent_version VARCHAR(64) NOT NULL,
    measurements_visibility VARCHAR(16) NOT NULL DEFAULT 'PRIVATE',
    progress_visibility VARCHAR(16) NOT NULL DEFAULT 'PRIVATE',
    intent_status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_challenge_invitation_intent_public (public_id),
    KEY idx_challenge_invitation_intent_invitation (invitation_id, invitation_generation, intent_status),
    KEY idx_challenge_invitation_intent_challenge (challenge_id, intent_status, id),
    CONSTRAINT fk_challenge_invitation_intent_invitation_scope
        FOREIGN KEY (invitation_id, crew_id, challenge_id) REFERENCES crew_invitations(id, crew_id, challenge_id) ON DELETE RESTRICT,
    CONSTRAINT fk_challenge_invitation_intent_crew
        FOREIGN KEY (crew_id) REFERENCES crews(id) ON DELETE RESTRICT,
    CONSTRAINT fk_challenge_invitation_intent_challenge_match
        FOREIGN KEY (crew_id, challenge_id) REFERENCES challenges(crew_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_challenge_invitation_intent_rule_scope
        FOREIGN KEY (rule_version_id, challenge_id) REFERENCES challenge_rule_versions(id, challenge_id) ON DELETE RESTRICT,
    CONSTRAINT chk_challenge_invitation_intent_status
        CHECK (intent_status IN ('PENDING','CONSUMED','INVALIDATED')),
    CONSTRAINT chk_challenge_invitation_intent_measurements
        CHECK (measurements_visibility IN ('PRIVATE','CHALLENGE')),
    CONSTRAINT chk_challenge_invitation_intent_progress
        CHECK (progress_visibility IN ('PRIVATE','CHALLENGE')),
    CONSTRAINT chk_challenge_invitation_intent_expiry
        CHECK (expires_at > created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
