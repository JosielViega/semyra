ALTER TABLE room_participants
    ADD COLUMN player_state TINYINT NULL,
    ADD COLUMN player_position_ms BIGINT UNSIGNED NULL,
    ADD COLUMN player_duration_ms BIGINT UNSIGNED NULL,
    ADD COLUMN player_sampled_at TIMESTAMP(3) NULL DEFAULT NULL;
