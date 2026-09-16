-- Canonical verified-email ownership is unique across FitCrew users.
--
-- The temporary-table insert is an intentional preflight. A duplicate active
-- verified address raises a duplicate-key error before any persistent schema
-- mutation, leaving reconciliation to Governance instead of choosing a user.
CREATE TEMPORARY TABLE fc_verified_email_uniqueness_preflight (
    email_canonical VARCHAR(320) NOT NULL,
    PRIMARY KEY (email_canonical)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO fc_verified_email_uniqueness_preflight (email_canonical)
SELECT email_canonical
FROM user_contact_emails
WHERE verification_status = 'VERIFIED'
  AND removed_at IS NULL;

DROP TEMPORARY TABLE fc_verified_email_uniqueness_preflight;

ALTER TABLE user_contact_emails
    ADD COLUMN verified_email_canonical VARCHAR(320) NULL AFTER removed_at;

UPDATE user_contact_emails
SET verified_email_canonical = email_canonical
WHERE verification_status = 'VERIFIED'
  AND removed_at IS NULL;

ALTER TABLE user_contact_emails
    ADD UNIQUE KEY uq_contact_email_verified_canonical (verified_email_canonical),
    ADD CONSTRAINT chk_contact_email_verified_canonical CHECK (
        (
            verification_status = 'VERIFIED'
            AND removed_at IS NULL
            AND verified_email_canonical IS NOT NULL
            AND verified_email_canonical = email_canonical
        )
        OR
        (
            (verification_status <> 'VERIFIED' OR removed_at IS NOT NULL)
            AND verified_email_canonical IS NULL
        )
    );
