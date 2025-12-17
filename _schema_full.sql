-- FULL SCHEMA (fresh install)
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','buyer') NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS teams (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  balance_usd DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS buyers (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  team_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  telegram_chat_id VARCHAR(64) NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  KEY(team_id),
  CONSTRAINT fk_buyer_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cards (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  buyer_id BIGINT UNSIGNED NULL,
  drop_name VARCHAR(120) NOT NULL,
  bank ENUM('privat','mono') NOT NULL,
  pan_enc LONGBLOB NOT NULL,
  pan_last4 CHAR(4) NOT NULL,
  cvv_enc LONGBLOB NOT NULL,
  issue_date DATE NULL,
  exp_month TINYINT UNSIGNED NOT NULL,
  exp_year SMALLINT UNSIGNED NOT NULL,
  limit_cap_uah INT NOT NULL DEFAULT 100000,
  balance_uah DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  setup_amount_uah DECIMAL(14,2) NULL,
  comment TEXT NULL,
  status ENUM('waiting','in_work','processing','await_refund','archived') NOT NULL DEFAULT 'waiting',
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  KEY(buyer_id),
  KEY(status),
  CONSTRAINT fk_card_buyer FOREIGN KEY (buyer_id) REFERENCES buyers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  card_id BIGINT UNSIGNED NOT NULL,
  type ENUM('topup','debit','hold') NOT NULL,
  amount_uah DECIMAL(14,2) NOT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  KEY(card_id),
  KEY(created_at),
  CONSTRAINT fk_payment_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS card_statements (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  card_id BIGINT UNSIGNED NOT NULL,
  file_name_orig VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  mime VARCHAR(100) NULL,
  size_bytes INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  KEY(card_id),
  CONSTRAINT fk_stmt_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fx_rates (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  base CHAR(3) NOT NULL,
  quote CHAR(3) NOT NULL,
  rate DECIMAL(10,4) NOT NULL,
  as_of DATE NOT NULL,
  source VARCHAR(50) NULL,
  created_at DATETIME NOT NULL,
  KEY(base,quote,as_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(120) NOT NULL PRIMARY KEY,
  `val` TEXT NOT NULL,
  `updated_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  entity_type VARCHAR(50) NOT NULL,
  action VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  payload_json JSON NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed admin if not exists (email: admin@example.com / admin123)
INSERT INTO users(name,email,password,role,created_at)
SELECT 'Administrator','admin@example.com', '$2b$12$1JE5BRQ1uCxLfuiUpyZBcuPL0JilHEOqGHcPbBT2QKY/54zQLVZxO', 'admin', NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email='admin@example.com');
