ALTER TABLE user_auth_identities
    ADD UNIQUE KEY uq_auth_identity_id_user (id, user_id);
