USE meditrail_pro;

CREATE TABLE IF NOT EXISTS health_readings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_member_id BIGINT UNSIGNED NOT NULL,
    metric ENUM('Weight', 'Blood pressure', 'Blood glucose', 'Heart rate', 'Temperature') NOT NULL,
    value_primary DECIMAL(7,2) NOT NULL,
    value_secondary DECIMAL(7,2) NULL,
    measured_at DATETIME NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_readings_member_metric (family_member_id, metric, measured_at),
    CONSTRAINT fk_readings_member
        FOREIGN KEY (family_member_id) REFERENCES family_members (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
