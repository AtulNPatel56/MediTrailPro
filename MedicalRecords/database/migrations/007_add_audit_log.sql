USE meditrail_pro;

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
