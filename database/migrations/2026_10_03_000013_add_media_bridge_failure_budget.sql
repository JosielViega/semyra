ALTER TABLE media_bridge_jobs
    ADD COLUMN failure_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER attempt_count,
    ADD COLUMN cleanup_through_attempt INT UNSIGNED NOT NULL DEFAULT 0 AFTER failure_count;

UPDATE media_bridge_jobs
SET failure_count = CASE
        WHEN attempt_count = 0 THEN 0
        WHEN status = 'failed' AND last_error_code = 'worker_shutdown' THEN attempt_count - 1
        WHEN status IN ('claimed', 'starting', 'running') THEN attempt_count - 1
        ELSE attempt_count
    END,
    cleanup_through_attempt = 0;
