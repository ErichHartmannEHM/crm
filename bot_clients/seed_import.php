<?php
// bot_clients/seed_import.php — импорт с нормализацией chat_id (если пришли положительные)
require_once __DIR__.'/../lib/db.php';
if (!function_exists('db_exec')) { function db_exec($sql,$p=[]) { $st=db()->prepare($sql); $st->execute($p); return $st; } }

db_exec("CREATE TABLE IF NOT EXISTS client_telegram_chats (
  chat_id BIGINT NOT NULL,
  thread_id INT NULL,
  type VARCHAR(32) NOT NULL,
  title VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (chat_id, thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) { http_response_code(400); echo 'bad json'; exit; }

$ins=0;
foreach ($data as $row) {
  $id_raw = $row['chat_id'] ?? null;
  if ($id_raw === null || $id_raw === '') continue;
  $type = strtolower($row['type'] ?? 'group');
  $title = $row['title'] ?? null;

  // Нормализуем: если пришёл положительный id — конвертируем в Bot API chat_id
  if (is_numeric($id_raw)) {
    $idn = (int)$id_raw;
    if ($idn > 0) {
      if ($type === 'supergroup' || $type === 'channel') $idn = (int)('-100'.$idn);
      else $idn = -$idn;
    }
  } else {
    $idn = (int)$id_raw;
  }

  if (!in_array($type, ['group','supergroup'], true)) continue;
  db_exec("INSERT INTO client_telegram_chats (chat_id, thread_id, type, title, is_active, last_seen)
           VALUES (:id, NULL, :type, :title, 1, NOW())
           ON DUPLICATE KEY UPDATE type=VALUES(type), title=VALUES(title), is_active=1, last_seen=NOW()",
         [':id'=>$idn, ':type'=>$type, ':title'=>$title]);
  $ins++;
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'imported'=>$ins], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
