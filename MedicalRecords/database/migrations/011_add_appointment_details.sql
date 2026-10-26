USE meditrail_pro;

ALTER TABLE appointments
    ADD COLUMN category VARCHAR(40) NOT NULL DEFAULT 'Specialist' AFTER family_member_id,
    ADD COLUMN location VARCHAR(160) NOT NULL DEFAULT '' AFTER provider_name,
    ADD COLUMN visit_mode ENUM('In Person', 'Virtual', 'Other') NOT NULL DEFAULT 'In Person' AFTER location,
    ADD COLUMN has_time TINYINT(1) NOT NULL DEFAULT 1 AFTER starts_at,
    ADD COLUMN pre_notes TEXT NULL AFTER has_time,
    ADD COLUMN result_notes TEXT NULL AFTER pre_notes;

CREATE TABLE appointment_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    stored_name CHAR(36) NOT NULL,
    mime_type VARCHAR(60) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_appointment_attachments_stored (stored_name),
    KEY idx_appointment_attachments_appointment (appointment_id),
    CONSTRAINT fk_appointment_attachments_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
