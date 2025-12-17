<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

$__root = dirname(__DIR__);
if (is_file($__root . '/lib/db.php'))     require_once $__root . '/lib/db.php';
if (is_file($__root . '/lib/helpers.php')) require_once $__root . '/lib/helpers.php';

spl_autoload_register(function ($class) {
    $prefix = 'Timer\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    // avoid backslash escaping issues by using chr(92)
    $relative_class = str_replace(chr(92), '/', $relative_class);
    $file = __DIR__ . '/src/' . $relative_class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function timer_log(string $message): void {
    $file = __DIR__ . '/var/worker.log';
    @file_put_contents($file, '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $message . PHP_EOL, FILE_APPEND);
}

function timer_ensure_schema(): void {
    if (!function_exists('db_exec')) return;
    try {
        db_exec("CREATE TABLE IF NOT EXISTS `project_clock` (
            `id` TINYINT NOT NULL PRIMARY KEY,
            `current_time` DATETIME NOT NULL,
            `last_real_ts` BIGINT UNSIGNED NOT NULL,
            `mode` ENUM('manual','auto') NOT NULL DEFAULT 'manual',
            `speed` DOUBLE NOT NULL DEFAULT 1.0,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db_exec("INSERT IGNORE INTO `project_clock`
            (`id`,`current_time`,`last_real_ts`,`mode`,`speed`)
            VALUES (1,'2025-01-01 00:00:00', UNIX_TIMESTAMP(), 'manual', 1.0)");

        db_exec("CREATE TABLE IF NOT EXISTS `scheduled_jobs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `chat_id` VARCHAR(64) NOT NULL,
            `message_text` TEXT NOT NULL,
            `scheduled_at` DATETIME NOT NULL,
            `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            `try_count` INT NOT NULL DEFAULT 0,
            `last_error` TEXT NULL,
            `sent_at` DATETIME NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_status_time` (`status`,`scheduled_at`),
            KEY `idx_chat` (`chat_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ignore */ }
}
