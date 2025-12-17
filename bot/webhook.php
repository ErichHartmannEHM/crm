<?php
// /bot_clients/webhook.php — Webhook для бота рассылок «Клиенты»
declare(strict_types=1);
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/settings.php';

// Minimal DB helper
if (!function_exists('db_exec')) {
  function db_exec(string $sql, array $params=[]){
    $pdo = db(); $st = $pdo->prepare($sql); $st->execute($params); return $st;
  }
}


// (опционально) секрет — если сохранён settings.key = tg_clients_webhook_secret
$needSecret = (string)(setting_get('tg_clients_webhook_secret') ?? '');
if ($needSecret !== '') {
  $hdr = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
  if ($hdr !== $needSecret) { http_response_code(401); echo 'bad secret'; exit; }
}

// Получаем JSON
$raw = file_get_contents('php://input');
$update = json_decode((string)$raw, true);
if (!is_array($update)) { http_response_code(400); echo 'bad json'; exit; }

$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS client_telegram_chats (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id BIGINT NOT NULL,
  thread_id INT NULL,
  type VARCHAR(32) NULL,
  title VARCHAR(255) NULL,
  username VARCHAR(64) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_chat_thread (chat_id, thread_id),
  KEY idx_active (is_active),
  KEY idx_last (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// helpers
function upsert_client_chat(PDO $pdo, array $chat, ?int $threadId=null, bool $active=true): void {
  $cid = isset($chat['id']) ? (string)$chat['id'] : '0';
  if ($cid === '0') return;
  $type = (string)($chat['type'] ?? '');
  $title = (string)($chat['title'] ?? '');
  $username = (string)($chat['username'] ?? '');
  $thr = $threadId !== null ? $threadId : null;
  db_exec("INSERT INTO client_telegram_chats (chat_id, thread_id, type, title, username, is_active, last_seen)
           VALUES (?,?,?,?,?,?,NOW())
           ON DUPLICATE KEY UPDATE type=VALUES(type), title=VALUES(title), username=VALUES(username),
                                   is_active=VALUES(is_active), last_seen=VALUES(last_seen), thread_id=VALUES(thread_id)",
           [$cid, $thr, $type, $title, $username, $active?1:0]);
}

// обработка событий
if (isset($update['message'])) {
  $m = $update['message'];
  $chat = (array)($m['chat'] ?? []);
  $threadId = isset($m['message_thread_id']) ? (int)$m['message_thread_id'] : null;
  upsert_client_chat($pdo, $chat, $threadId, true);

  $txt = trim((string)($m['text'] ?? ''));
  if ($txt === '/start' || $txt === '/start@'.(string)($m['via_bot']['username'] ?? '')) {
    // ответим чем-то нейтральным
    $bot_token = (function(){
      try { $v = setting_get('clients.bot_token'); if($v) return (string)$v; } catch (Throwable $e) {}
      $file = __DIR__.'/token.php'; if (is_file($file)) { require_once $file; if (defined('CLIENTS_BOT_TOKEN')) return (string)CLIENTS_BOT_TOKEN; }
      $env = getenv('TG_CLIENT_BOT_TOKEN'); if ($env) return (string)$env;
      return null;
    })();
    if ($bot_token) {
      $url = 'https://api.telegram.org/bot'.$bot_token.'/sendMessage';
      $payload = [
        'chat_id' => (string)($chat['id'] ?? ''),
        'text' => "Бот активирован. Этот бот используется только для рассылок в клиентских чатах.",
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
      ];
      if ($threadId !== null) $payload['message_thread_id'] = $threadId;
      $ch = curl_init($url);
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE), CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>20]);
      curl_exec($ch); curl_close($ch);
    }
  }
  echo json_encode(['ok'=>true]); exit;
}

if (isset($update['my_chat_member'])) {
  $obj = $update['my_chat_member'];
  $chat = (array)($obj['chat'] ?? []);
  $new = (array)($obj['new_chat_member'] ?? []);
  $status = (string)($new['status'] ?? '');
  $active = !in_array($status, ['left','kicked','banned'], true);
  upsert_client_chat($pdo, $chat, null, $active);
  echo json_encode(['ok'=>true]); exit;
}

if (isset($update['chat_member'])) {
  $obj = $update['chat_member'];
  $chat = (array)($obj['chat'] ?? []);
  $new = (array)($obj['new_chat_member'] ?? []);
  $status = (string)($new['status'] ?? '');
  $active = !in_array($status, ['left','kicked','banned'], true);
  upsert_client_chat($pdo, $chat, null, $active);
  echo json_encode(['ok'=>true]); exit;
}

// default
echo json_encode(['ok'=>true]);
