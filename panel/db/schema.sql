-- ApexNode Panel Database Schema
CREATE DATABASE IF NOT EXISTS apexnode CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE apexnode;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(128) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','operator','viewer') NOT NULL DEFAULT 'viewer',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    hostname VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    cpu_cores INT NOT NULL DEFAULT 4,
    ram_mb INT NOT NULL DEFAULT 8192,
    disk_gb INT NOT NULL DEFAULT 100,
    daemon_version VARCHAR(32) DEFAULT '1.0.0',
    status ENUM('online','offline','degraded') NOT NULL DEFAULT 'online',
    latency_ms INT DEFAULT 12,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS servers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    game ENUM('minecraft-java','minecraft-bedrock','cs2','rust') NOT NULL,
    node_id INT NOT NULL,
    owner_id INT NOT NULL,
    port INT NOT NULL DEFAULT 25565,
    cpu_limit INT NOT NULL DEFAULT 2,
    ram_mb INT NOT NULL DEFAULT 2048,
    disk_gb INT NOT NULL DEFAULT 10,
    status ENUM('online','offline','starting','stopping','installing','crashed') NOT NULL DEFAULT 'offline',
    startup_command VARCHAR(255) DEFAULT '',
    version VARCHAR(32) DEFAULT 'latest',
    players_online INT DEFAULT 0,
    players_max INT DEFAULT 20,
    cpu_usage FLOAT DEFAULT 0,
    ram_usage_mb INT DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS server_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    line TEXT NOT NULL,
    level ENUM('info','warn','error','system') NOT NULL DEFAULT 'info',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX (server_id, id),
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(64) NOT NULL,
    target VARCHAR(128) DEFAULT '',
    detail VARCHAR(255) DEFAULT '',
    ip VARCHAR(45) DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(64) PRIMARY KEY,
    v TEXT
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS user_themes (
    user_id INT PRIMARY KEY,
    accent VARCHAR(16) DEFAULT '#00F0FF',
    radius VARCHAR(8) DEFAULT '12px',
    density VARCHAR(16) DEFAULT 'comfortable',
    mode VARCHAR(8) DEFAULT 'dark',
    font VARCHAR(32) DEFAULT 'Outfit',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
