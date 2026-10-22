ALTER TABLE users
    ADD COLUMN is_premium TINYINT(1) NOT NULL DEFAULT 0 AFTER desktop_profile_id;
