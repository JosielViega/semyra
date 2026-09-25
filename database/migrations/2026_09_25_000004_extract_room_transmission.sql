ALTER TABLE rooms
    DROP COLUMN youtube_video_id;

CREATE TABLE room_transmissions (
    room_id BIGINT UNSIGNED PRIMARY KEY,
    owner_participant_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    youtube_video_id CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    started_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    CONSTRAINT room_transmissions_room_fk
        FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
