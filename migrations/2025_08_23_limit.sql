-- 2025-08-23 Monthly limit logic
ALTER TABLE `cards`
  ADD COLUMN IF NOT EXISTS `limit_cap_uah` DECIMAL(14,2) NOT NULL DEFAULT 100000 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `limit_remaining_uah` DECIMAL(14,2) NULL AFTER `limit_cap_uah`,
  ADD COLUMN IF NOT EXISTS `limit_last_reset_month` INT NULL AFTER `limit_remaining_uah`;

UPDATE `cards`
SET `limit_remaining_uah` = IFNULL(`limit_remaining_uah`, `limit_cap_uah`),
    `limit_last_reset_month` = IFNULL(`limit_last_reset_month`, EXTRACT(YEAR_MONTH FROM NOW()))
WHERE `limit_remaining_uah` IS NULL OR `limit_last_reset_month` IS NULL;
