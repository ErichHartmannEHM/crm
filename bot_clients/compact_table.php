<?php
// bot_clients/compact_table.php — безопасная «компактация» с уникальным именем бэкапа
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
auth_require(); auth_require_admin();

if (!function_exists('db_exec')) { function db_exec($sql,$p=[]) { $st=db()->prepare($sql); $st->execute($p); return $st; } }
if (!function_exists('db_one')) { function db_one($sql,$p=[]) { $st=db_exec($sql,$p); $row=$st->fetch(PDO::FETCH_ASSOC); return $row ?: null; } }

$before = db_one("SELECT COUNT(*) c, COUNT(DISTINCT chat_id) u FROM client_telegram_chats");

db_exec("CREATE TABLE IF NOT EXISTS client_telegram_chats_tmp LIKE client_telegram_chats");
db_exec("TRUNCATE client_telegram_chats_tmp");
db_exec("INSERT INTO client_telegram_chats_tmp (chat_id, thread_id, type, title, is_active, last_seen)
         SELECT chat_id, NULL AS thread_id, MAX(type), MAX(title), 1, MAX(last_seen)
         FROM client_telegram_chats
         WHERE type IN ('group','supergroup')
         GROUP BY chat_id");

$backup = 'client_telegram_chats_backup';
// Если бэкап уже есть — добавим суффикс по дате
$exists = db_one("SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t", [':t'=>$backup]);
if ($exists) {
  $backup .= '_' . date('Ymd_His');
}
db_exec("RENAME TABLE client_telegram_chats TO `$backup`, client_telegram_chats_tmp TO client_telegram_chats");

$after = db_one("SELECT COUNT(*) c, COUNT(DISTINCT chat_id) u FROM client_telegram_chats");

header('Content-Type: text/plain; charset=utf-8');
echo "До: всего записей={$before['c']}, уникальных чатов={$before['u']}\n";
echo "После: всего записей={$after['c']}, уникальных чатов={$after['u']}\n";
echo "Бэкап: $backup (можно удалить после проверки)\n";
