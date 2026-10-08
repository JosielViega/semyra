ALTER TABLE users
    ADD COLUMN desktop_profile_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER password_hash,
    ADD UNIQUE KEY users_desktop_profile_id_unique (desktop_profile_id);
