CREATE DATABASE IF NOT EXISTS meditrail_pro
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE meditrail_pro;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 0,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- Existing installs: run database/migrations/002_add_users_and_ownership.sql instead of re-importing.
CREATE TABLE IF NOT EXISTS family_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id BIGINT UNSIGNED NULL,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    relationship VARCHAR(40) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_family_members_active (is_active),
    INDEX idx_family_members_owner (owner_user_id),
    CONSTRAINT fk_family_members_owner
        FOREIGN KEY (owner_user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_member_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    dose_instructions VARCHAR(180) NOT NULL,
    schedule_time TIME NULL,
    status ENUM('Active', 'Paused', 'Completed') NOT NULL DEFAULT 'Active',
    supply_count INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_medications_member_status (family_member_id, status),
    CONSTRAINT fk_medications_family_member
        FOREIGN KEY (family_member_id) REFERENCES family_members (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appointments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_member_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'Specialist',
    title VARCHAR(140) NOT NULL,
    provider_name VARCHAR(120) NOT NULL,
    location VARCHAR(160) NOT NULL DEFAULT '',
    visit_mode ENUM('In Person', 'Virtual', 'Other') NOT NULL DEFAULT 'In Person',
    starts_at DATETIME NOT NULL,
    has_time TINYINT(1) NOT NULL DEFAULT 1,
    pre_notes TEXT NULL,
    result_notes TEXT NULL,
    status ENUM('Scheduled', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Scheduled',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_appointments_member_start (family_member_id, starts_at),
    CONSTRAINT fk_appointments_family_member
        FOREIGN KEY (family_member_id) REFERENCES family_members (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appointment_attachments (
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

CREATE TABLE IF NOT EXISTS documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_member_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(140) NOT NULL,
    category ENUM('Lab report', 'Prescription', 'Imaging', 'Insurance', 'Other') NOT NULL DEFAULT 'Other',
    original_name VARCHAR(190) NOT NULL,
    stored_name CHAR(36) NOT NULL,
    mime_type VARCHAR(60) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documents_stored (stored_name),
    KEY idx_documents_member (family_member_id, uploaded_at),
    CONSTRAINT fk_documents_member
        FOREIGN KEY (family_member_id) REFERENCES family_members (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    entity VARCHAR(40) NOT NULL DEFAULT '',
    entity_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user_time (user_id, created_at, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id, created_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

INSERT INTO family_members (first_name, last_name, relationship)
SELECT 'Alex', 'Morgan', 'Self'
WHERE NOT EXISTS (SELECT 1 FROM family_members WHERE first_name = 'Alex' AND last_name = 'Morgan');

INSERT INTO family_members (first_name, last_name, relationship)
SELECT 'Jamie', 'Morgan', 'Partner'
WHERE NOT EXISTS (SELECT 1 FROM family_members WHERE first_name = 'Jamie' AND last_name = 'Morgan');

INSERT INTO medications (family_member_id, name, dose_instructions, schedule_time, status)
SELECT f.id, demo.name, demo.dose_instructions, demo.schedule_time, 'Active'
FROM family_members AS f
JOIN (
    SELECT 'Example medication A' AS name, 'Use clinician-provided instructions' AS dose_instructions, '08:00:00' AS schedule_time
    UNION ALL
    SELECT 'Example medication B', 'Use clinician-provided instructions', '20:00:00'
) AS demo ON f.first_name = 'Alex' AND f.last_name = 'Morgan'
WHERE NOT EXISTS (
    SELECT 1 FROM medications AS existing
    WHERE existing.family_member_id = f.id AND existing.name = demo.name
);

INSERT INTO appointments (family_member_id, title, provider_name, starts_at, status)
SELECT f.id, 'Example check-up', 'Example Family Clinic', DATE_ADD(NOW(), INTERVAL 2 DAY), 'Scheduled'
FROM family_members AS f
WHERE f.first_name = 'Alex' AND f.last_name = 'Morgan'
  AND NOT EXISTS (
      SELECT 1 FROM appointments AS existing
      WHERE existing.family_member_id = f.id AND existing.title = 'Example check-up'
  );

INSERT INTO appointments (family_member_id, title, provider_name, starts_at, status)
SELECT f.id, 'Example follow-up', 'Example Health Centre', DATE_ADD(NOW(), INTERVAL 7 DAY), 'Scheduled'
FROM family_members AS f
WHERE f.first_name = 'Jamie' AND f.last_name = 'Morgan'
  AND NOT EXISTS (
      SELECT 1 FROM appointments AS existing
      WHERE existing.family_member_id = f.id AND existing.title = 'Example follow-up'
  );
