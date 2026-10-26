USE meditrail_pro;

ALTER TABLE users
    ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER password_hash;
