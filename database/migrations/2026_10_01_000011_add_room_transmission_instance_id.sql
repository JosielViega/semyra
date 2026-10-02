ALTER TABLE room_transmissions
    ADD COLUMN instance_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER room_id;

UPDATE room_transmissions
SET instance_id = LOWER(REPLACE(UUID(), '-', ''))
WHERE instance_id IS NULL;

ALTER TABLE room_transmissions
    MODIFY COLUMN instance_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD UNIQUE INDEX uq_room_transmissions_instance_id (instance_id);
