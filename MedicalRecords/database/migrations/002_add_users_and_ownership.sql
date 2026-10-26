USE meditrail_pro;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- Records that existed before accounts have no owner; the first registered account adopts them.
ALTER TABLE family_members
    ADD COLUMN owner_user_id BIGINT UNSIGNED NULL AFTER id,
    ADD INDEX idx_family_members_owner (owner_user_id),
    ADD CONSTRAINT fk_family_members_owner
        FOREIGN KEY (owner_user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
