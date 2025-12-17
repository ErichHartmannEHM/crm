-- scanner module schema (optional; создастся автоматически на первом запуске)
CREATE TABLE IF NOT EXISTS scanner_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id VARCHAR(32) NOT NULL UNIQUE,
  worker_id BIGINT UNSIGNED NULL,
  last_status VARCHAR(255) NULL,
  last_dt DATETIME NULL,
  last_hash CHAR(40) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (worker_id), INDEX (last_status), INDEX (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS scanner_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id VARCHAR(32) NOT NULL,
  row_order INT NOT NULL,
  d VARCHAR(10) NULL, t VARCHAR(5) NULL,
  status VARCHAR(255) NULL, comment TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (request_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS scanner_proxies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  proxy_url VARCHAR(255) NOT NULL,
  refresh_url VARCHAR(255) DEFAULT NULL,
  assigned_worker_id BIGINT UNSIGNED DEFAULT NULL,
  batch_limit INT DEFAULT 10,
  refresh_wait_sec INT DEFAULT 20,
  active TINYINT(1) DEFAULT 1,
  last_refreshed_at DATETIME DEFAULT NULL,
  last_used_at DATETIME DEFAULT NULL,
  UNIQUE KEY uniq_worker (assigned_worker_id), INDEX (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS scanner_proxy_stats (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proxy_id BIGINT UNSIGNED NOT NULL,
  counter INT DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_proxy (proxy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS scanner_settings (
  id TINYINT PRIMARY KEY DEFAULT 1,
  default_proxy_url VARCHAR(255) DEFAULT NULL,
  default_refresh_url VARCHAR(255) DEFAULT NULL,
  default_batch_limit INT DEFAULT 10,
  default_refresh_wait_sec INT DEFAULT 20
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO scanner_settings (id) VALUES (1);
