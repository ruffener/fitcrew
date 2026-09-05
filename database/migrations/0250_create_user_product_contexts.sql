CREATE TABLE user_product_contexts (
    user_id BIGINT UNSIGNED NOT NULL,
    selected_crew_id BIGINT UNSIGNED NULL,
    selected_challenge_id BIGINT UNSIGNED NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (user_id),
    KEY idx_user_product_contexts_crew (selected_crew_id),
    KEY idx_user_product_contexts_challenge (selected_challenge_id),
    CONSTRAINT fk_user_product_contexts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_product_contexts_crew FOREIGN KEY (selected_crew_id) REFERENCES crews(id) ON DELETE SET NULL,
    CONSTRAINT fk_user_product_contexts_challenge FOREIGN KEY (selected_challenge_id) REFERENCES challenges(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
