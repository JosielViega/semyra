ALTER TABLE room_participants
    ADD COLUMN player_instance_key_hash CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NULL
        AFTER participant_key_hash;
