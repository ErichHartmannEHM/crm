<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../../config.php';
$pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo "Укажите id: ?id=13"; exit; }

$tm = $pdo->prepare("SELECT * FROM tg_timers WHERE id=:id");
$tm->execute([':id'=>$id]);
$timer = $tm->fetch();
if (!$timer) { echo "Таймер #$id не найден"; exit; }

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$weekday = (int)$now->format('N');

function dayAllowed(?string $mask, int $weekday1to7): bool {
  if ($mask === null || $mask === '') return true;
  if (preg_match('~^[01]{7}$~', $mask)) {
     $i = $weekday1to7 - 1; return $mask[$i] === '1';
  }
  if (ctype_digit($mask)) {
     $bits = (int)$mask; $bit = 1 << ($weekday1to7-1); return (bool)($bits & $bit);
  }
  return true;
}
function isDueThisMinuteUTC(?string $hhmm, DateTimeImmutable $nowUTC): bool {
  if (!$hhmm || !preg_match('~^\d{2}:\d{2}$~', $hhmm)) return false;
  return substr($hhmm,0,2)===$nowUTC->format('H') && substr($hhmm,3,2)===$nowUTC->format('i');
}

$todayAllowed = dayAllowed($timer['day_mask'] ?? null, $weekday);
$forceToday = false;
if (!empty($timer['force_today_until'])) {
  $ft = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timer['force_today_until'], new DateTimeZone('UTC'));
  if ($ft && $ft > $now) $forceToday = true;
}
if (!$todayAllowed && $forceToday) $todayAllowed = true;

$dueThisMinute = isDueThisMinuteUTC($timer['time_hhmm'] ?? null, $now);
$every = !empty($timer['every_minutes']) ? (int)$timer['every_minutes'] : 0;

echo "<h2>Диагностика таймера #{$id}</h2>";
echo "<pre>";
echo "enabled: ".($timer['enabled']?'1':'0')."\n";
echo "scope: {$timer['scope']}  only_inwork: {$timer['only_inwork']}\n";
echo "time_hhmm: {$timer['time_hhmm']}  every_minutes: {$every}\n";
echo "day_mask: ".($timer['day_mask']??'')."  weekday[1..7]: {$weekday}\n";
echo "force_today_until: ".($timer['force_today_until']??'NULL')."\n";
echo "todayAllowed: ".($todayAllowed?'YES':'no')."  dueThisMinute: ".($dueThisMinute?'YES':'no')."\n";
echo "now(UTC): ".$now->format('Y-m-d H:i:s')."\n";
echo "</pre>";

if (!$todayAllowed) echo "<b>Причина:</b> на текущий день маска запрещает запуск. Нажмите «Сбросить сегодня» или включите день недели в маске.<br>";

if ($timer['scope'] === 'drop-chats') {
  // тест выборки чатов
  $hasMulti = (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name='drop_telegram_chats'")->fetchColumn();
  if ($hasMulti) {
    $rows = $pdo->query("SELECT d.id drop_id, dtc.chat_id, dtc.thread_id, dtc.is_active
                         FROM drops d JOIN drop_telegram_chats dtc ON dtc.drop_id=d.id
                         WHERE dtc.is_active=1 LIMIT 50")->fetchAll();
  } else {
    $rows = $pdo->query("SELECT d.id drop_id, d.telegram_chat_id chat_id, NULL thread_id, 1 is_active
                         FROM drops d WHERE d.telegram_chat_id <> '' LIMIT 50")->fetchAll();
  }
  echo "<h3>Пример чатов (до 50)</h3><pre>".htmlspecialchars(print_r($rows,true))."</pre>";

  if ((int)$timer['only_inwork'] === 1) {
    // проверим, есть ли вообще карты In Work
    $cards = $pdo->query("SELECT id, status, drop_id, drop_name FROM cards WHERE archived=0 LIMIT 200")->fetchAll();
    echo "<h3>Статусы карт (sample 200)</h3><pre>";
    foreach ($cards as $c) echo "#{$c['id']} | ".($c['status']??'NULL')." | drop_id=".($c['drop_id']??'NULL')." | drop_name=".($c['drop_name']??'NULL')."\n";
    echo "</pre>";
    echo "Если список пуст/нет статусов типа \"in_work/в работе\" — фильтр ничего не найдёт.\n";
  }
}

echo "<hr><b>Итог:</b> если todayAllowed=YES и dueThisMinute=YES (или every_minutes попадает), отправка должна идти. Если чатов/карт нет — причина во входных данных.\n";
