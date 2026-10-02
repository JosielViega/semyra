CREATE TABLE media_bridge_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id BIGINT UNSIGNED NOT NULL,
    transmission_instance_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_ref VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    desired_state VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL,
    worker_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    lease_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    lease_expires_at DATETIME(3) NULL,
    ingress_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    stopped_at DATETIME(3) NULL,
    UNIQUE KEY uq_media_bridge_jobs_transmission_instance (transmission_instance_id),
    KEY idx_media_bridge_jobs_dispatch (desired_state, status, lease_expires_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
