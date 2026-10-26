USE meditrail_pro;

CREATE TABLE IF NOT EXISTS dose_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    medication_id BIGINT UNSIGNED NOT NULL,
    dose_date DATE NOT NULL,
    outcome ENUM('Taken', 'Skipped') NOT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dose_medication_date (medication_id, dose_date),
    CONSTRAINT fk_dose_events_medication
        FOREIGN KEY (medication_id) REFERENCES medications (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
