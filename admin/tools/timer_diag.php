<?php
declare(strict_types=1);
date_default_timezone_set(getenv('APP_TZ') ?: 'Europe/Kiev');
$ROOT = dirname(__DIR__, 1);
$BASE = dirname(__DIR__, 1); // /admin
$APP = dirname($BASE);       // project root

require_once $APP . '/cron/telegram_dispatch.php'; // reuses DB and helpers

$now = date('Y-m-d H:i');
$wbit = weekday_bit();

$stmt = $pdo->query("SELECT id,scope,message,time1,time2,time3,only_inwork,enabled,active,mask,last_sent_date FROM tg_broadcast_schedules ORDER BY id DESC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

?><!doctype html><meta charset="utf-8"><title>Timer Diag</title>
<style>body{font-family:system-ui,Arial,sans-serif;background:#0b1220;color:#d7e3ff;padding:20px}
table{border-collapse:collapse;width:100%}td,th{border:1px solid #24314d;padding:6px 8px}small{opacity:.8}</style>
<h2>Диагностика таймеров (без SSH)</h2>
<p>Сейчас: <b><?=htmlspecialchars($now)?></b> | Бит дня недели: <b><?=$wbit?></b></p>
<table>
<tr><th>ID</th><th>Кому</th><th>Время</th><th>Только InWork</th><th>Mask</th><th>Сегодня сработает?</th><th>Предпросмотр чатов</th></tr>
<?php foreach ($rows as $r):
  $due_today = (((int)$r['mask'] >> $wbit) & 1) === 1;
  $times = array_filter([substr((string)$r['time1'],0,5), substr((string)$r['time2'],0,5), substr((string)$r['time3'],0,5)]);
  $times_s = implode(', ', $times);
  $hm = date('H:i');
  $due_now = in_array($hm, $times, true);
  $why = $due_today ? ($due_now ? 'ДА, в эту минуту' : 'Сегодня да, но не сейчас') : 'НЕТ (mask=0 или день выключен)';
?>
<tr>
  <td>#<?=$r['id']?></td>
  <td><?=htmlspecialchars($r['scope'])?></td>
  <td><?=$times_s?:'<i>не задано</i>'?></td>
  <td><?=$r['only_inwork']?'да':'нет'?></td>
  <td><?=$r['mask']?></td>
  <td><?=$why?></td>
  <td><a style="color:#8ab4ff" href="timer_preview.php?id=<?=$r['id']?>">Предпросмотр</a> | 
      <a style="color:#8ab4ff" href="timer_mask_fix.php?id=<?=$r['id']?>&mask=127">Включить все дни</a></td>
</tr>
<?php endforeach; ?>
</table>
<p><a style="color:#8ab4ff" href="timer_mask_fix.php?all=1">Починить маску у всех (mask=0 → 127)</a> |
   <a style="color:#8ab4ff" href="log_view.php">Открыть лог рассылок</a></p>
