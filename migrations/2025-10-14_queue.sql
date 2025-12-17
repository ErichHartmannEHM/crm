
CREATE TABLE IF NOT EXISTS tg_broadcast_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  schedule_id INT UNSIGNED NOT NULL,
  slot TINYINT UNSIGNED NOT NULL,
  run_at DATETIME NOT NULL,
  run_key CHAR(10) NOT NULL,
  status ENUM('pending','sent','error') NOT NULL DEFAULT 'pending',
  sent_at DATETIME NULL,
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_sched_day_slot (schedule_id, run_key),
  KEY idx_run (status, run_at),
  KEY idx_sched (schedule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
