-- ApexNode Panel v6 — server-level permissions and egg source tracking
USE apexnode;

CREATE TABLE IF NOT EXISTS server_access (
    server_id INT NOT NULL,
    user_id INT NOT NULL,
    view_server TINYINT(1) NOT NULL DEFAULT 0,
    view_console TINYINT(1) NOT NULL DEFAULT 0,
    control_server TINYINT(1) NOT NULL DEFAULT 0,
    view_files TINYINT(1) NOT NULL DEFAULT 0,
    manage_files TINYINT(1) NOT NULL DEFAULT 0,
    view_backups TINYINT(1) NOT NULL DEFAULT 0,
    manage_backups TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (server_id, user_id),
    CONSTRAINT fk_server_access_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    CONSTRAINT fk_server_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE eggs ADD COLUMN IF NOT EXISTS source_hash CHAR(64) DEFAULT NULL;