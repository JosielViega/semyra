ALTER TABLE room_participants
    ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER room_id,
    ADD KEY room_participants_room_user_index (room_id, user_id),
    ADD CONSTRAINT room_participants_user_foreign
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE room_transmissions
    ADD COLUMN owner_user_id BIGINT UNSIGNED NULL AFTER owner_participant_key_hash,
    ADD KEY room_transmissions_owner_user_index (owner_user_id),
    ADD CONSTRAINT room_transmissions_owner_user_foreign
        FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE SET NULL;
