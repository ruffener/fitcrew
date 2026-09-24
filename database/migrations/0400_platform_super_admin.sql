-- ADMIN-1: role vocabulary and a database-enforced singleton, without promotion.
-- NULL keys allow any number of ordinary users/admins; the value 1 is unique.
ALTER TABLE users
    DROP CONSTRAINT chk_users_platform_role,
    ADD CONSTRAINT chk_users_platform_role CHECK (
        BINARY platform_role_code IN ('USER', 'PLATFORM_ADMIN', 'PLATFORM_SUPER_ADMIN')
    ),
    ADD COLUMN platform_super_admin_slot TINYINT
        GENERATED ALWAYS AS (
            CASE WHEN BINARY platform_role_code = 'PLATFORM_SUPER_ADMIN' THEN 1 ELSE NULL END
        ) PERSISTENT,
    ADD UNIQUE KEY uq_users_single_platform_super_admin (platform_super_admin_slot);
