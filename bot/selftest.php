<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/settings.php';
require_once __DIR__.'/../lib/telegram.php';

header('Content-Type: text/plain; charset=utf-8');

$err = null; $raw = null;
$me = telegram_api('getMe', [], $raw, $err);
if (!$me || empty($me['ok'])) {
  echo "getMe FAILED: ".($err?:'n/a')."\nRaw: ".($raw?:'')."\n";
  http_response_code(500);
  exit;
}

$bot = $me['result'] ?? [];
echo "Bot ok: @".($bot['username']??'unknown')." (id ".($bot['id']??'n/a').")\n";

// send test message to admin chat
$cfg = app_config();
$admin_chat = setting_get('admin_chat_id', '') ?: ($cfg['telegram']['admin_chat_id'] ?? ($cfg['security']['telegram_admin_chat_id'] ?? ''));
if (!$admin_chat) {
  echo "No admin_chat_id in settings or config.php\n";
  exit;
}

$ok = telegram_send((string)$admin_chat, "✅ Bot self-test OK at ".date('Y-m-d H:i:s'));
echo $ok ? "Sent message to admin_chat_id={$admin_chat}\n" : "Failed to send message (check logs)\n";
