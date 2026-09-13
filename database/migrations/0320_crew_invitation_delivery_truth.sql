-- Website-owned Crew invitation delivery truth.
-- Legacy sent_at values are not reliable transport-acceptance evidence because
-- earlier runtime set them before transport was attempted. Reset that legacy
-- field to unknown/pending truth rather than falsely claiming delivery.
ALTER TABLE crew_invitations
    MODIFY sent_at DATETIME(6) NULL DEFAULT NULL,
    ADD COLUMN transport_status VARCHAR(24) NOT NULL DEFAULT 'PENDING_SEND' AFTER resend_count,
    ADD COLUMN transport_driver VARCHAR(32) NULL AFTER transport_status,
    ADD COLUMN transport_message_id VARCHAR(191) NULL AFTER transport_driver,
    ADD COLUMN transport_attempted_at DATETIME(6) NULL AFTER transport_message_id,
    ADD CONSTRAINT chk_crew_invitation_transport_status
        CHECK (transport_status IN ('PENDING_SEND','TRANSPORT_ACCEPTED','TRANSPORT_FAILED'));

UPDATE crew_invitations
SET sent_at = NULL,
    transport_status = 'PENDING_SEND',
    transport_driver = NULL,
    transport_message_id = NULL,
    transport_attempted_at = NULL;
