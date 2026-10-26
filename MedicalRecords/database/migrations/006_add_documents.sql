USE meditrail_pro;

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
