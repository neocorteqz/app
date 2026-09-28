-- ApexNode Panel v5 — job cancellation + installer settings
USE apexnode;

CREATE TABLE IF NOT EXISTS database_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    username VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    database_name VARCHAR(80) NOT NULL,
    host VARCHAR(45) NOT NULL DEFAULT 'localhost',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_db_user (username, database_name)
) ENGINE=InnoDB;

-- Add cancel-request flag for in-progress cancellation
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS cancel_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

-- Panel installation profile (for install.sh output + coexistence with cPanel/Plesk/DirectAdmin)
INSERT IGNORE INTO settings (k, v) VALUES ('panel_port', '3000'),
                                          ('panel_url_path', '/'),
                                          ('coexist_mode', 'standalone');
