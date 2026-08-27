-- ============================================================
-- OAUTH RELAY DATABASE SCHEMA & INITIAL DATA MIGRATION
-- Database: oauth_db
-- ============================================================

CREATE DATABASE IF NOT EXISTS `oauth_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `oauth_db`;

-- 1. Tabel Whitelist Host
CREATE TABLE IF NOT EXISTS `allowed_hosts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `host` VARCHAR(255) NOT NULL UNIQUE,
    `app_name` VARCHAR(100) DEFAULT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Tabel Whitelist User & Admin
CREATE TABLE IF NOT EXISTS `allowed_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `name` VARCHAR(255) DEFAULT NULL,
    `role` VARCHAR(50) DEFAULT 'user',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Tabel Audit Access Logs
CREATE TABLE IF NOT EXISTS `access_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `event_type` VARCHAR(50) NOT NULL DEFAULT 'RELAY',
    `status` VARCHAR(50) NOT NULL,
    `app_name` VARCHAR(100) DEFAULT NULL,
    `user_email` VARCHAR(255) DEFAULT NULL,
    `user_name` VARCHAR(255) DEFAULT NULL,
    `google_sub` VARCHAR(255) DEFAULT NULL,
    `return_host` VARCHAR(255) DEFAULT NULL,
    `return_url` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Initial Seed Data: Allowed Hosts
INSERT INTO `allowed_hosts` (`host`, `app_name`, `is_active`) VALUES
('localhost', 'Localhost', 1),
('127.0.0.1', 'Localhost IP', 1),
('cepad', 'CEPAD Server', 1),
('cepad.tailb17b07.ts.net', 'CEPAD Tailscale', 1),
('cedev', 'CEDEV Server', 1),
('100.122.111.21', 'Internal IP', 1),
('apps.sinjaikab.go.id', 'Portal Apps', 1),
('e-praja.sinjaikab.go.id', 'E-PRAJA', 1),
('enikda.sinjaikab.go.id', 'ENIKDA', 1),
('e-pad.sinjaikab.go.id', 'E-PAD', 1)
ON DUPLICATE KEY UPDATE `app_name` = VALUES(`app_name`);

-- Initial Seed Data: Allowed Users & Admin
INSERT INTO `allowed_users` (`email`, `name`, `role`, `is_active`) VALUES
('uttibatu@gmail.com', 'Admin Uttibatu', 'admin', 1)
ON DUPLICATE KEY UPDATE `role` = VALUES(`role`), `is_active` = VALUES(`is_active`);
