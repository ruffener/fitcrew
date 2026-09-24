-- ADMIN-1 ONE-TIME OPERATOR BOOTSTRAP. Run only after Governance acceptance,
-- migration 0400, and operator verification of the canonical user below.
-- This is a manual operator operation, never a migration or an Admin UI route.
-- Use a MariaDB CLI client supporting DELIMITER. Do not run with --force.

-- 1. Inspect the existing ACTIVE, verified Google identity and its canonical user.
SELECT u.id, u.public_id, u.display_name, u.account_status, u.platform_role_code,
       i.provider_key, i.email_at_provider, i.provider_email_verified
FROM users u JOIN user_auth_identities i ON i.user_id = u.id
WHERE i.provider_key = 'GOOGLE' AND i.issuer = 'https://accounts.google.com'
  AND LOWER(TRIM(i.email_at_provider)) = 'ruffener@gmail.com'
  AND i.identity_status = 'ACTIVE' AND i.provider_email_verified = 1;

-- 2. Replace only this placeholder with the verified canonical users.public_id.
-- Leaving the placeholder unchanged safely rejects the operation.
SET @fc_admin_bootstrap_public_id = 'PASTE_VERIFIED_CANONICAL_USER_PUBLIC_ID';

DELIMITER $$
BEGIN NOT ATOMIC
    DECLARE v_user_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_google_user_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_google_users INT DEFAULT 0;
    DECLARE v_super_count INT DEFAULT 0;
    DECLARE v_migration_count INT DEFAULT 0;
    DECLARE v_old_role VARCHAR(32) DEFAULT NULL;
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF @fc_admin_bootstrap_public_id IS NULL OR
       @fc_admin_bootstrap_public_id NOT REGEXP '^[0-7][0-9A-HJKMNP-TV-Z]{25}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Verify and supply the canonical user public ID first.';
    END IF;

    START TRANSACTION;
    SELECT COUNT(*) INTO v_migration_count FROM schema_migrations
    WHERE migration = '0400_platform_super_admin.sql';
    IF v_migration_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 0400 must be applied first.';
    END IF;

    SELECT id, platform_role_code, account_status INTO v_user_id, v_old_role, v_status
    FROM users WHERE public_id = @fc_admin_bootstrap_public_id FOR UPDATE;
    IF v_user_id IS NULL OR v_status <> 'ACTIVE' OR v_old_role NOT IN ('USER', 'PLATFORM_ADMIN') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Canonical user is missing, inactive or already Super Admin.';
    END IF;

    SELECT COUNT(DISTINCT user_id), MIN(user_id) INTO v_google_users, v_google_user_id
    FROM user_auth_identities
    WHERE provider_key = 'GOOGLE' AND issuer = 'https://accounts.google.com'
      AND LOWER(TRIM(email_at_provider)) = 'ruffener@gmail.com'
      AND identity_status = 'ACTIVE' AND provider_email_verified = 1
    FOR UPDATE;
    IF v_google_users <> 1 OR v_google_user_id <> v_user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Google bootstrap identity does not identify exactly the selected canonical user.';
    END IF;

    SELECT COUNT(*) INTO v_super_count FROM users WHERE platform_role_code = 'PLATFORM_SUPER_ADMIN';
    IF v_super_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A Super Admin already exists. Bootstrap refused.';
    END IF;

    -- The unique generated-column index also rejects concurrent second promotions.
    UPDATE users SET platform_role_code = 'PLATFORM_SUPER_ADMIN'
    WHERE id = v_user_id AND platform_role_code = v_old_role AND account_status = 'ACTIVE';
    IF ROW_COUNT() <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bootstrap role update did not affect exactly one user.';
    END IF;

    INSERT INTO audit_events (actor_user_id, event_type, target_type, target_id, outcome, metadata_json)
    VALUES (v_user_id, 'ADMIN_SUPER_BOOTSTRAPPED', 'USER', CAST(v_user_id AS CHAR), 'SUCCESS',
        JSON_OBJECT('old_role', v_old_role, 'new_role', 'PLATFORM_SUPER_ADMIN', 'method', 'manual_operator_bootstrap'));
    COMMIT;
END$$
DELIMITER ;

-- 3. Verify exactly one row and the recorded successful role transition.
SELECT public_id, display_name, account_status, platform_role_code
FROM users WHERE platform_role_code = 'PLATFORM_SUPER_ADMIN';
SELECT occurred_at, actor_user_id, event_type, target_id, outcome, metadata_json
FROM audit_events WHERE event_type = 'ADMIN_SUPER_BOOTSTRAPPED'
ORDER BY id DESC LIMIT 1;
