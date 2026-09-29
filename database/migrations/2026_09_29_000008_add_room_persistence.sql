ALTER TABLE rooms
    ADD COLUMN created_by_user_id BIGINT UNSIGNED NULL AFTER code,
    ADD COLUMN last_activity_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) AFTER created_at,
    ADD KEY rooms_creator_activity_index (created_by_user_id, last_activity_at),
    ADD CONSTRAINT rooms_creator_foreign
        FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL;

UPDATE rooms
SET last_activity_at = CURRENT_TIMESTAMP(3);
