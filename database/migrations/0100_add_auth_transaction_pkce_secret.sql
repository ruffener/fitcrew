ALTER TABLE auth_transactions
    ADD COLUMN pkce_verifier_secret_envelope VARCHAR(1024)
        CHARACTER SET ascii COLLATE ascii_bin NULL
        AFTER pkce_verifier_hash;
