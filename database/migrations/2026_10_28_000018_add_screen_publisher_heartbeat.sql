ALTER TABLE room_transmissions
    ADD COLUMN publisher_last_seen_at TIMESTAMP(3) NULL AFTER live_edge_updated_at,
    ADD INDEX room_transmissions_screen_publisher_liveness
        (source_type, publisher_last_seen_at, room_id, instance_id, revision);
