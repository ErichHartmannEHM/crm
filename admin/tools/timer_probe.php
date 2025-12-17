<?php
// admin/tools/timer_probe.php — подробная диагностика таймеров (Киев)
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php'; auth_require(); auth_require_admin();
require_once __DIR__ . '/../../lib/scheduler.php';

header('Content-Type: text/plain; charset=utf-8');

function kyiv_tz(): DateTimeZone { try { return new DateTimeZone('Europe/Kyiv'); } catch (Throwable $e) { return new DateTimeZone('Europe/Kiev'); } }
$pdo = db();
$now = new DateTimeImmutable('now', kyiv_tz());
$wday = (int)$now->format('N');
$hhmm = $now->format('H:i');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$sql="SELECT * FROM tg_broadcast_schedules WHERE enabled=1";
$params=[]; if($id){ $sql.=" AND id=?"; $params[]=$id; }
$sql.=" ORDER BY id DESC";

$rows = db_all($sql,$params);

echo "now=".$now->format('Y-m-d H:i:s')." TZ=Kyiv (".$now->getTimezone()->getName().")\n";
foreach ($rows as $tm) {
  $mask=(int)$tm['mask'];
  $sentMask=(int)$tm['sent_mask'];
  $today=$now->format('Y-m-d');
  $last=$tm['last_sent_date']??null;
  if ($last!==$today) $sentMask=0;
  $t1=substr((string)$tm['time1'],0,5); $t2=substr((string)$tm['time2'],0,5); $t3=substr((string)$tm['time3'],0,5);

  $due=array_fill(0,3,false);
  if ($t1===$hhmm && ($sentMask&1)===0) $due[0]=true;
  if ($t2===$hhmm && ($sentMask&2)===0) $due[1]=true;
  if ($t3===$hhmm && ($sentMask&4)===0) $due[2]=true;

  echo "#{$tm['id']} scope={$tm['scope']} only_inwork={$tm['only_inwork']} mask={$mask} today_allowed=".(weekday_allowed($mask,$wday)?'yes':'no')." times={$t1},{$t2},{$t3} hhmm={$hhmm} last={$last} sent_mask={$sentMask} due=".json_encode($due)."\n";
}
