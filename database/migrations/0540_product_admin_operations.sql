-- Website/Product-owned ADMIN-2B / ADMIN-2C durability.
-- Stores idempotent operation receipts only; product truth remains in existing Crew/Challenge tables.
CREATE TABLE product_admin_operations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    target_type VARCHAR(16) NOT NULL,
    target_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation_key VARCHAR(48) NOT NULL,
    result_json LONGTEXT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_product_admin_operation_request (actor_user_id, request_key),
    KEY idx_product_admin_target (target_type, target_public_id, created_at),
    CONSTRAINT fk_product_admin_operation_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_product_admin_operation_target CHECK (target_type IN ('CREW','CHALLENGE')),
    CONSTRAINT chk_product_admin_operation_result CHECK (JSON_VALID(result_json)),
    CONSTRAINT chk_product_admin_operation_key CHECK (operation_key IN (
        'crew_edit','crew_transfer_owner','crew_archive','crew_restore','crew_remove_member',
        'challenge_edit_name','challenge_edit_rule_draft','challenge_remove_participant',
        'challenge_end','challenge_archive','challenge_unarchive'
    ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
