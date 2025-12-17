<?php
// bot_clients/fix_positive_ids.php — исправление записей с положительным chat_id
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
auth_require(); auth_require_admin();

if (!function_exists('db_exec')) { function db_exec($sql,$p=[]) { $st=db()->prepare($sql); $st->execute($p); return $st; } }
if (!function_exists('db_all')) { function db_all($sql,$p=[]) { $st=db_exec($sql,$p); return $st->fetchAll(PDO::FETCH_ASSOC); } }

$rows = db_all("SELECT chat_id, type, title FROM client_telegram_chats WHERE chat_id > 0");
$fixed=0; $skipped=0;

foreach ($rows as $r) {
  $id = (int)$r['chat_id']; $type=strtolower($r['type']);
  if ($type==='supergroup' || $type==='channel') $new = (int)('-100'.$id);
  else $new = -$id;
  // Вставим/обновим новую запись и удалим старую
  db_exec("INSERT INTO client_telegram_chats (chat_id, thread_id, type, title, is_active, last_seen)
           VALUES (:id, NULL, :type, :title, 1, NOW())
           ON DUPLICATE KEY UPDATE type=VALUES(type), title=VALUES(title), is_active=1, last_seen=NOW()",
        [':id'=>$new, ':type'=>$type, ':title'=>$r['title']]);
  db_exec("DELETE FROM client_telegram_chats WHERE chat_id=:old AND thread_id IS NULL", [':old'=>$id]);
  $fixed++;
}

header('Content-Type: text/plain; charset=utf-8');
echo "Исправлено записей: $fixed\n";
