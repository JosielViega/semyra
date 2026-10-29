CREATE TABLE room_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id BIGINT UNSIGNED NOT NULL,
    participant_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    display_name VARCHAR(40) NOT NULL,
    client_message_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    message VARCHAR(1000) NOT NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY room_messages_idempotency_unique (room_id, participant_key_hash, client_message_id),
    KEY room_messages_room_id_index (room_id, id),
    KEY room_messages_room_created_index (room_id, created_at),
    CONSTRAINT room_messages_room_foreign
        FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE,
    CONSTRAINT room_messages_user_foreign
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
