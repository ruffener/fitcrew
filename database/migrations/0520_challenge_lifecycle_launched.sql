-- Website-owned Challenge Journey v1 lifecycle contract correction.
-- READY_TO_LAUNCH is intentionally retired; readiness is not proof of launch.
-- Fail closed before schema mutation if any production row still uses it.

CREATE TEMPORARY TABLE fc_challenge_lifecycle_0520_preflight (
    incompatible_ready_to_launch_rows BIGINT UNSIGNED NOT NULL,
    CONSTRAINT chk_fc_challenge_lifecycle_0520_preflight
        CHECK (incompatible_ready_to_launch_rows = 0)
) ENGINE=InnoDB;

INSERT INTO fc_challenge_lifecycle_0520_preflight (incompatible_ready_to_launch_rows)
SELECT COUNT(*)
FROM challenges
WHERE lifecycle_status = 'READY_TO_LAUNCH';

ALTER TABLE challenges
    DROP CONSTRAINT chk_challenges_lifecycle,
    ADD CONSTRAINT chk_challenges_lifecycle CHECK (
        lifecycle_status IN (
            'DRAFT',
            'FORMING_CREW',
            'LAUNCHED',
            'BASELINE',
            'LIVE',
            'FINAL_WEEK_LIVE',
            'RESULTS_UNDER_REVIEW',
            'COMPLETED'
        )
    );

DROP TEMPORARY TABLE fc_challenge_lifecycle_0520_preflight;
