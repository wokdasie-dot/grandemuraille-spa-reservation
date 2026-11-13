-- Schéma MySQL 8.0 / MariaDB 10.5+ pour les réservations
-- de La Grande Muraille de Chine (Spa).
--
-- Recommandation : créez une base neuve, par exemple :
--   CREATE DATABASE grande_muraille_resa
--     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- puis : mysql ... grande_muraille_resa < schema.sql
--
-- Le schéma fonctionne aussi dans une base existante (ex. grandemuhw2014),
-- mais une base dédiée évite tout mélange avec un contenu inconnu.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS reservations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  date DATE NOT NULL,
  time_slot CHAR(5) NOT NULL,
  guests TINYINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(200) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  note VARCHAR(1000) NOT NULL DEFAULT '',
  table_id VARCHAR(32) NOT NULL DEFAULT '',
  table_seats TINYINT UNSIGNED NOT NULL DEFAULT 0,
  table_label VARCHAR(64) NOT NULL DEFAULT '',
  status ENUM('en_attente', 'confirmee', 'annulee') NOT NULL DEFAULT 'en_attente',
  created_at DATETIME NOT NULL,
  ip_hash CHAR(64) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  INDEX idx_date_slot (date, time_slot),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compte administrateur optionnel (le hash du mot de passe peut aussi
-- être mis uniquement dans config.php).
CREATE TABLE IF NOT EXISTS admin_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_hash CHAR(64) NOT NULL,
  action VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_ip_action_time (ip_hash, action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
