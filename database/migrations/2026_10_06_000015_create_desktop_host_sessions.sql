CREATE TABLE desktop_host_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id BIGINT UNSIGNED NOT NULL,
    transmission_instance_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    transmission_revision BIGINT UNSIGNED NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    owner_participant_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    permission VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    selector CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    validator_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at TIMESTAMP(3) NOT NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    revoked_at TIMESTAMP(3) NULL,
    UNIQUE KEY desktop_host_sessions_selector_unique (selector),
    KEY desktop_host_sessions_room_id_index (room_id),
    KEY desktop_host_sessions_transmission_instance_id_index (transmission_instance_id),
    KEY desktop_host_sessions_owner_user_id_index (owner_user_id),
    KEY desktop_host_sessions_expires_at_index (expires_at),
    CONSTRAINT desktop_host_sessions_room_id_foreign
        FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE,
    CONSTRAINT desktop_host_sessions_owner_user_id_foreign
        FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
