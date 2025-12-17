<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/auth.php';  auth_require(); auth_require_admin();
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/scheduler.php';
@require_once __DIR__ . '/../../lib/telegram.php';

header('Content-Type: text/plain; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo "Use ?id=<schedule_id>"; exit; }

$sch = db_row("SELECT * FROM tg_broadcast_schedules WHERE id=?", [$id]);
if (!$sch) { echo "No schedule"; exit; }

$list = recipients_for_schedule(db(), $sch);

echo "Force send schedule #{$sch['id']} scope={$sch['scope']} only_inwork={$sch['only_inwork']} recipients="
   . count($list) . "\n";

$ok = 0; $fail = 0;
foreach ($list as $r) {
  $params = ['chat_id' => $r['chat_id'], 'text' => (string)$sch['message'], 'disable_web_page_preview' => true];
  if ($r['thread'] !== null && (int)$r['thread'] > 0) {
    $params['message_thread_id'] = (int)$r['thread'];
  }
  $raw = null; $err = null;
  try {
    $resp = telegram_api('sendMessage', $params, $raw, $err);
    if (!empty($resp['ok'])) $ok++; else { $fail++; echo "ERR chat={$r['chat_id']} ".($err ?: json_encode($resp))."\n"; }
  } catch (Throwable $e) {
    $fail++; echo "EXC chat={$r['chat_id']} ".$e->getMessage()."\n";
  }
  usleep(150000);
}
echo "done: ok={$ok} fail={$fail}\n";
