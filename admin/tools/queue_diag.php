<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/auth.php';  auth_require(); auth_require_admin();
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/settings.php';
require_once __DIR__ . '/../../lib/scheduler.php';

header('Content-Type: text/plain; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);

if ($id > 0) {
  $sch = db_row("SELECT * FROM tg_broadcast_schedules WHERE id=?", [$id]);
  if (!$sch) { echo "no schedule\n"; exit; }

  echo "schedule #{$sch['id']} scope={$sch['scope']} only_inwork={$sch['only_inwork']} "
     . "times={$sch['time1']},{$sch['time2']},{$sch['time3']} mask={$sch['mask']}\n\n";

  $rows = db_all("SELECT * FROM tg_broadcast_queue WHERE schedule_id=? ORDER BY run_at", [$id]);
  foreach ($rows as $r) {
    echo "#{$r['id']} run_at={$r['run_at']} slot={$r['slot']} status={$r['status']} key={$r['run_key']}\n";
  }
  echo "total: ".count($rows)."\n";
} else {
  $rows = db_all("SELECT q.*, s.scope, s.only_inwork
                  FROM tg_broadcast_queue q
                  JOIN tg_broadcast_schedules s ON s.id=q.schedule_id
                  ORDER BY q.run_at LIMIT 100");
  foreach ($rows as $r) {
    echo "[{$r['status']}] sched={$r['schedule_id']} run_at={$r['run_at']} slot={$r['slot']} key={$r['run_key']}\n";
  }
  echo "total: ".count($rows)."\n";
}
