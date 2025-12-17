<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php'; auth_require(); auth_require_admin();
require_once __DIR__ . '/../../lib/scheduler.php';

header('Content-Type: text/plain; charset=utf-8');

function tz_server(): DateTimeZone {
  if (defined('APP_TZ') && APP_TZ) {
    try { return new DateTimeZone(APP_TZ); } catch (Throwable $e) {}
  }
  $name = @date_default_timezone_get();
  if (!$name) $name = 'UTC';
  try { return new DateTimeZone($name); } catch (Throwable $e) { return new DateTimeZone('UTC'); }
}

$pdo = db();
$now = new DateTimeImmutable('now', tz_server());
$wday = (int)$now->format('N');
$nowTs = $now->getTimestamp();
$todayStr = $now->format('Y-m-d');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$sql = "SELECT * FROM tg_broadcast_schedules WHERE enabled=1";
$params=[]; if($id){ $sql .= " AND id=?"; $params[]=$id; }
$sql .= " ORDER BY id DESC";
$rows = db_all($sql, $params);

echo "server now=".$now->format('Y-m-d H:i:s')." tz=".$now->getTimezone()->getName()." (tolerance=".TOLERANCE_SEC."s)\n";

foreach ($rows as $tm) {
  $times = [ $tm['time1'] ?? null, $tm['time2'] ?? null, $tm['time3'] ?? null ];
  echo "#{$tm['id']} scope={$tm['scope']} only_inwork={$tm['only_inwork']} mask={$tm['mask']}\n";
  foreach ($times as $slot=>$hhmmss) {
    if (empty($hhmmss)) continue;
    $hhmm = substr((string)$hhmmss,0,5);
    $runAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $todayStr.' '.$hhmm, tz_server());
    $runTs = $runAt->getTimestamp();
    $inWindow = ($nowTs >= $runTs - TOLERANCE_SEC) && ($nowTs < $runTs + 60);

    $exists = db_col("SELECT COUNT(*) FROM tg_broadcast_runs WHERE schedule_id=? AND run_date=? AND slot=?", [(int)$tm['id'], $todayStr, (int)$slot]);

    echo "  slot={$slot} time={$hhmm} runAt=".$runAt->format('H:i:s')." diffSec=".($nowTs-$runTs)." inWindow=".($inWindow?'yes':'no')." already=".($exists?'yes':'no')."\n";
  }
}
