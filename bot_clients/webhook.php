<?php
// bot_clients/webhook.php — нормализация: только group/supergroup, thread_id=NULL
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

$update = json_decode(file_get_contents('php://input'), true) ?: [];

function upsert_chat($id,$type,$title,$active){
  if (!in_array($type, ['group','supergroup'], true)) return;
  db_exec("INSERT INTO client_telegram_chats (chat_id, thread_id, type, title, is_active, last_seen)
           VALUES (:id, NULL, :type, :title, :active, NOW())
           ON DUPLICATE KEY UPDATE type=VALUES(type), title=VALUES(title), is_active=VALUES(is_active), last_seen=NOW()",
          [':id'=>$id, ':type'=>$type, ':title'=>$title, ':active'=>$active?1:0]);
}

if (isset($update['message'])) {
  $c = $update['message']['chat'] ?? null;
  if ($c) upsert_chat($c['id'], $c['type'] ?? 'group', $c['title'] ?? null, 1);
}
if (isset($update['my_chat_member'])) {
  $mc = $update['my_chat_member']; $c=$mc['chat'] ?? null;
  $status=$mc['new_chat_member']['status'] ?? 'member';
  $active = in_array($status,['member','administrator','creator'],true);
  if ($c) upsert_chat($c['id'], $c['type'] ?? 'group', $c['title'] ?? null, $active);
}
http_response_code(200); echo 'ok';
