-- Set default mask to all days and fix zero masks
ALTER TABLE tg_broadcast_schedules MODIFY mask TINYINT(3) UNSIGNED NOT NULL DEFAULT 127;
UPDATE tg_broadcast_schedules SET mask=127 WHERE mask=0;
