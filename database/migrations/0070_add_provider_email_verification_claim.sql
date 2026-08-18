ALTER TABLE user_auth_identities
    CHANGE COLUMN email_verified_at_provider email_verification_observed_at DATETIME(6) NULL,
    ADD COLUMN provider_email_verified TINYINT(1) NULL AFTER email_at_provider,
    ADD CONSTRAINT chk_auth_identity_provider_email_verified
        CHECK (provider_email_verified IS NULL OR provider_email_verified IN (0, 1));
