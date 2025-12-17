<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/auth.php';  auth_require(); auth_require_admin();
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/scheduler.php';

header('Content-Type: text/plain; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo "Use ?id=<schedule_id>"; exit; }

$sch = db_row("SELECT * FROM tg_broadcast_schedules WHERE id=?", [$id]);
if (!$sch) { echo "No schedule"; exit; }

$list = recipients_for_schedule(db(), $sch);

echo "schedule #{$sch['id']} scope={$sch['scope']} only_inwork={$sch['only_inwork']} msg="
   . substr((string)$sch['message'], 0, 80) . "\n";
echo "recipients: " . count($list) . "\n";
foreach ($list as $r) {
  echo " - " . $r['chat_id'] . ($r['thread'] !== null ? " [thread {$r['thread']}]" : "") . "\n";
}
