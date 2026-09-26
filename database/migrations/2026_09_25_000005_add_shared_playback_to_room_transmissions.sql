ALTER TABLE room_transmissions
    ADD COLUMN media_mode VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unknown' AFTER youtube_video_id,
    ADD COLUMN playback_state VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'playing' AFTER revision,
    ADD COLUMN playback_position_ms BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER playback_state,
    ADD COLUMN playback_at_live_edge TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER playback_position_ms,
    ADD COLUMN playback_revision BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER playback_at_live_edge,
    ADD COLUMN playback_updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) AFTER playback_revision,
    ADD COLUMN live_edge_position_ms BIGINT UNSIGNED NULL AFTER playback_updated_at,
    ADD COLUMN live_edge_updated_at TIMESTAMP(3) NULL AFTER live_edge_position_ms;
