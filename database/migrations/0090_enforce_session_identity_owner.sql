ALTER TABLE user_sessions
    ADD KEY idx_user_sessions_identity_user (auth_identity_id, user_id),
    ADD CONSTRAINT fk_user_session_identity_user
        FOREIGN KEY (auth_identity_id, user_id)
        REFERENCES user_auth_identities (id, user_id)
        ON DELETE RESTRICT;
