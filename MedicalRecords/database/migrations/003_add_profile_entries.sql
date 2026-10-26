USE meditrail_pro;

CREATE TABLE IF NOT EXISTS profile_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_member_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('Allergy', 'Condition', 'Emergency contact') NOT NULL,
    title VARCHAR(120) NOT NULL,
    detail VARCHAR(255) NOT NULL DEFAULT '',
    phone VARCHAR(30) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_profile_member_kind (family_member_id, kind),
    CONSTRAINT fk_profile_entries_member
        FOREIGN KEY (family_member_id) REFERENCES family_members (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
