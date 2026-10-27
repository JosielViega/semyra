ALTER TABLE room_participants
    ADD COLUMN player_left_at TIMESTAMP(3) NULL
        AFTER player_instance_key_hash,
    ADD KEY room_participants_room_owner_activity_index
        (room_id, user_id, participant_key_hash, last_seen_at, player_left_at);
