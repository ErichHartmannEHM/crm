<?php
declare(strict_types=1);

/**
 * Telegram broadcast & timer (Europe/Kyiv)
 * - CLI/cron runner
 * - Admin UI for "send now" and scheduled messages
 *
 * Cron:
 *   * * * * * /usr/bin/php /path/to/admin/telegram.php >/dev/null 2>&1
 */

// ---- Timezone ----
date_default_timezone_set('Europe/Kyiv');

// ---- Auth (CLI не требует) ----
require_once __DIR__.'/../lib/auth.php';
if (PHP_SAPI !== 'cli') { auth_require(); auth_require_admin(); }

// ---- Project libs ----
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../project_timer/bootstrap.php';
timer_ensure_schema();
@require_once __DIR__.'/../lib/telegram.php';

// ---------------------- helpers ----------------------

function csrf_field_safe(): string {
  return function_exists('csrf_field') ? csrf_field() : '';
}
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function has_col_local(PDO $pdo, string $table, string $col): bool {
  if(function_exists('has_col')) return has_col($pdo,$table,$col);
  try{
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->execute([$table,$col]);
    return (int)$stmt->fetchColumn() > 0;
  }catch(Exception $e){ return false; }
}
function table_exists(PDO $pdo, string $table): bool {
  try{
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
  }catch(Exception $e){ return false; }
}

// Fallback status lists
if(!function_exists('statuses_inwork')){
  function statuses_inwork(): array { return ['inwork','in_work','processing','active']; }
}
if(!function_exists('statuses_waiting')){
  function statuses_waiting(): array { return ['waiting','pending']; }
}

// Simple DB shims
if(!function_exists('db_all')){
  function db_all(string $sql, array $params=[]): array { $pdo=db(); $st=$pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC); }
}
if(!function_exists('db_exec')){
  function db_exec(string $sql, array $params=[]): int { $pdo=db(); $st=$pdo->prepare($sql); $st->execute($params); return $st->rowCount(); }
}

// ---------------------- migration ----------------------

function tg_migrate(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS tg_broadcast_schedules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(16) NOT NULL, -- 'drop' | 'team'
    message TEXT NOT NULL,
    time1 TIME NULL,
    time2 TIME NULL,
    time3 TIME NULL,
    only_inwork TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    mask INT NOT NULL DEFAULT 127,
    sent_mask INT NOT NULL DEFAULT 0,
    last_sent_date DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  foreach([
    'only_inwork TINYINT(1) NOT NULL DEFAULT 0',
    'active TINYINT(1) NOT NULL DEFAULT 1',
    'enabled TINYINT(1) NOT NULL DEFAULT 1',
    'mask INT NOT NULL DEFAULT 127',
    'sent_mask INT NOT NULL DEFAULT 0',
    'last_sent_date DATE NULL',
    'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
  ] as $colDef){
    [$colName] = explode(' ', $colDef, 2);
    if(!has_col_local($pdo, 'tg_broadcast_schedules', $colName)){
      $pdo->exec("ALTER TABLE tg_broadcast_schedules ADD COLUMN ".$colDef);
    }
  }
}

// ---------------------- core sending ----------------------

function tg_broadcast_send(PDO $pdo, string $scope, string $text, bool $onlyInwork=false, bool $onlyWaiting=false): int {
  $text = trim($text);
  if($text==='') return 0;
  $raw=null; $err=null; $sent=0;

  if($scope==='team'){
    if(($onlyInwork || $onlyWaiting) && table_exists($pdo,'cards') && has_col_local($pdo,'cards','team_id')){
      $statuses=[];
      if($onlyInwork)  $statuses=array_merge($statuses,statuses_inwork());
      if($onlyWaiting) $statuses=array_merge($statuses,statuses_waiting());
      $statuses=array_values(array_unique($statuses));
      if(!empty($statuses)){
        $ph=implode(',', array_fill(0, count($statuses), '?'));
        $sql="SELECT DISTINCT ttc.chat_id
              FROM team_telegram_chats ttc
              JOIN cards c ON c.team_id = ttc.team_id
              WHERE ttc.is_active=1";
        if(has_col_local($pdo,'team_telegram_chats','active')) $sql.=" AND ttc.active=1";
        $sql.=" AND c.status IN ($ph)";
        $targets = db_all($sql,$statuses);
      }
    }
    if(!isset($targets)){
      $sql="SELECT DISTINCT chat_id FROM team_telegram_chats WHERE is_active=1";
      if(has_col_local($pdo,'team_telegram_chats','active')) $sql.=" AND active=1";
      $targets=db_all($sql);
    }
    foreach($targets as $r){
      $cid=(string)($r['chat_id']??'');
      if($cid==='') continue;
      telegram_api('sendMessage', ['chat_id'=>$cid,'text'=>$text,'disable_web_page_preview'=>true], $raw,$err);
      $sent++; usleep(120000);
    }
    return $sent;
  }

  if($scope==='drop'){
    $hasThread = has_col_local($pdo,'drop_telegram_chats','thread_id');
    $selThread = $hasThread ? "dtc.thread_id" : "NULL AS thread_id";

    if(($onlyInwork || $onlyWaiting) && table_exists($pdo,'cards')){
      $statuses=[];
      if($onlyInwork)  $statuses=array_merge($statuses,statuses_inwork());
      if($onlyWaiting) $statuses=array_merge($statuses,statuses_waiting());
      $statuses=array_values(array_unique($statuses));
      if(!empty($statuses)){
        $ph=implode(',', array_fill(0, count($statuses), '?'));
        $sql="SELECT DISTINCT dtc.chat_id, $selThread
              FROM drop_telegram_chats dtc
              JOIN drops d ON d.id = dtc.drop_id
              JOIN cards c ON (c.drop_id = d.id OR (c.drop_name IS NOT NULL AND c.drop_name = d.name))
              WHERE dtc.is_active=1";
        if(has_col_local($pdo,'drop_telegram_chats','active')) $sql.=" AND dtc.active=1";
        $sql.=" AND c.status IN ($ph)";
        $targets=db_all($sql,$statuses);
      }
    }
    if(!isset($targets)){
      $sql="SELECT DISTINCT chat_id, $selThread FROM drop_telegram_chats WHERE is_active=1";
      if(has_col_local($pdo,'drop_telegram_chats','active')) $sql.=" AND active=1";
      $targets=db_all($sql);
    }
    foreach($targets as $r){
      $cid=(string)($r['chat_id']??'');
      if($cid==='') continue;
      $params=['chat_id'=>$cid,'text'=>$text,'disable_web_page_preview'=>true];
      $thread=(isset($r['thread_id']) && $r['thread_id']!==null && $r['thread_id']!=='' && (int)$r['thread_id']>0)?(int)$r['thread_id']:null;
      if($thread) $params['message_thread_id']=$thread;
      telegram_api('sendMessage',$params,$raw,$err);
      $sent++; usleep(120000);
    }
    return $sent;
  }

  return 0;
}

// ---------------------- scheduler core ----------------------

function parse_time(?string $s): ?string {
  $s=trim((string)$s); if($s==='') return null;
  if(preg_match('~^([01]?\d|2[0-3]):([0-5]\d)$~',$s,$m)) return sprintf('%02d:%02d:00',(int)$m[1],(int)$m[2]);
  return null;
}

function tg_run_schedules(PDO $pdo): array {
  $tz = new DateTimeZone('UTC');
  $now = Timer\Clock::tickAndGet($pdo);
  $today = $now->format('Y-m-d');
  $dow = (int)$now->format('N'); // 1..7, Mon..Sun
  $dayBit = 1 << (($dow-1) & 7);

  $timers = db_all("SELECT * FROM tg_broadcast_schedules WHERE enabled=1 AND active=1 ORDER BY id ASC");
  $logs = [];

  foreach($timers as $t){
    $id = (int)($t['id']??0);
    if($id<=0) continue;

    $mask = (int)($t['mask'] ?? 127);
    if(($mask & $dayBit)===0){ $logs[]="timer#$id skipped (mask)"; continue; }

    $lastDate = (string)($t['last_sent_date'] ?? '');
    if($lastDate !== $today){
      db_exec("UPDATE tg_broadcast_schedules SET sent_mask=0,last_sent_date=? WHERE id=?",[$today,$id]);
      $t['sent_mask']=0;
    }
    $sentMask = (int)($t['sent_mask'] ?? 0);

    $times = [];
    foreach(['time1','time2','time3'] as $k){
      $v = parse_time((string)($t[$k] ?? '')); if($v!=='') $times[] = $v;
    }
    if(empty($times)){ $logs[]="timer#$id has no times"; continue; }

    foreach(array_values($times) as $idx => $hhmmss){
      $bit = (1 << $idx);
      if(($sentMask & $bit)!==0) continue;
      try{ $dt = new DateTimeImmutable($today.' '.$hhmmss, $tz); } catch(Exception $e){ $logs[]="timer#$id bad time '$hhmmss'"; continue; }
      if($now >= $dt){
        $scope = (string)($t['scope'] ?? 'drop');
        $message = (string)($t['message'] ?? '');
        if($message===''){ $logs[]="timer#$id empty message"; continue; }
        $onlyInwork = ((int)($t['only_inwork'] ?? 0) === 1);
        $sent = tg_broadcast_send($pdo, $scope, $message, $onlyInwork, false);
        db_exec("UPDATE tg_broadcast_schedules SET sent_mask = (sent_mask | ?) WHERE id=?",[$bit,$id]);
        $logs[] = "timer#$id [$scope @ $hhmmss] sent to $sent chats.";
      }
    }
  }
  return $logs;
}

// ---------------------- entry points ----------------------

$pdo = db();
tg_migrate($pdo);

// CLI: run timers
if (PHP_SAPI === 'cli') {
  $logs = tg_run_schedules($pdo);
  foreach($logs as $line){ echo $line.PHP_EOL; }
  exit(0);
}

// Web trigger BEFORE any HTML output
if (isset($_GET['run_timers']) && ($_GET['run_timers']==='1' || $_GET['run_timers']==='true')){
  $logs = tg_run_schedules($pdo);
  if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
  echo "OK\n".implode("\n",$logs);
  exit;
}

// ---- Session for flash ----
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

// ---------------------- POST handlers (no output yet) ----------------------

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['send_now']) && in_array($_POST['scope']??'', ['drop','team'], true)){
  $scope=(string)$_POST['scope'];
  $text=trim((string)($_POST['text']??''));
  $onlyIn = (int)($_POST['only_inwork_now']??0)===1;
  $onlyWait = (int)($_POST['only_waiting_now']??0)===1;
  if($text===''){ $_SESSION['__f']['err']='Введите текст.'; header('Location: /admin/telegram.php'); exit; }
  $ok = tg_broadcast_send($pdo, $scope, $text, $onlyIn, $onlyWait);
  $_SESSION['__f']['ok']='Отправлено: '.$ok;
  header('Location: /admin/telegram.php'); exit;
}

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_timer']) && in_array($_POST['scope']??'', ['drop','team'], true)){
  $scope=(string)$_POST['scope']; $message=trim((string)($_POST['message']??''));
  $t1=parse_time($_POST['time1']??''); $t2=parse_time($_POST['time2']??''); $t3=parse_time($_POST['time3']??'');
  $times=array_values(array_unique(array_filter([$t1,$t2,$t3]))); sort($times);
  if($message===''||empty($times)){ $_SESSION['__f']['err']='Введите сообщение и корректное время (HH:MM).'; header('Location: /admin/telegram.php'); exit; }
  $only=(int)($_POST['only_inwork']??0);
  $mask=0; foreach(['mon','tue','wed','thu','fri','sat','sun'] as $i=>$d){ if((int)($_POST['dow_'.$d]??0)===1) $mask|=(1<<$i); }
  if($mask===0) $mask=127;
  $sql="INSERT INTO tg_broadcast_schedules(scope,message,time1,time2,time3,only_inwork,active,created_at,mask,enabled,last_sent_date,sent_mask)
        VALUES(?,?,?,?,?,?,1,NOW(),?,1,NULL,0)";
  db_exec($sql, [$scope,$message,$times[0]??null,$times[1]??null,$times[2]??null,$only,$mask]);
  $_SESSION['__f']['ok']='Таймер создан.'; header('Location: /admin/telegram.php'); exit;
}

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle'])){
  $id=(int)($_POST['id']??0); $flag=(int)($_POST['active']??0)?1:0;
  db_exec("UPDATE tg_broadcast_schedules SET active=? WHERE id=?",[$flag,$id]);
  $_SESSION['__f']['ok']=$flag?'Таймер включён.':'Таймер на паузе.';
  header('Location: /admin/telegram.php'); exit;
}
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete'])){
  $id=(int)($_POST['id']??0);
  db_exec("DELETE FROM tg_broadcast_schedules WHERE id=?",[$id]);
  $_SESSION['__f']['ok']='Удалено.';
  header('Location: /admin/telegram.php'); exit;
}

// ---------------------- UI rendering ----------------------

// flash (read & clear)
$flash_err = $_SESSION['__f']['err'] ?? null;
$flash_ok  = $_SESSION['__f']['ok'] ?? null;
unset($_SESSION['__f']);

// data
$timers = db_all("SELECT * FROM tg_broadcast_schedules ORDER BY id DESC");

// build page content (with markers for auto-detect)
ob_start();
?>

<!--TG_PAGE_START-->
<style>
/* --- Telegram page modern compact UI --- */
.tg-page{max-width:1200px;margin:0 auto;}
.tg-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media (max-width: 1100px){ .tg-grid{grid-template-columns:1fr;} }
.tg-card{background:var(--panel,#101a27);border:1px solid var(--border,#223149);
  border-radius:16px;padding:16px 16px 14px;box-shadow:0 1px 0 rgba(255,255,255,.04),0 8px 18px rgba(0,0,0,.25)}
.tg-card h3{margin:0 0 10px 0;font-size:18px;font-weight:700}
.tg-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.tg-row .grow{flex:1 1 auto}
.tg-note{color:var(--muted,#9db1c9);font-size:12px}
.tg-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:10px}
.tg-textarea{width:100%;min-height:110px;border-radius:12px;border:1px solid #2a3a58;padding:10px 12px;background:var(--panel-2,#0e1520);color:var(--text,#e5efff)}
.tg-time input{width:90px;border-radius:10px;border:1px solid #2a3a58;padding:8px 10px;background:var(--panel-2,#0e1520);color:var(--text,#e5efff)}
.tg-switch label{display:inline-flex;align-items:center;gap:8px;margin-right:14px;font-size:13px}
.tg-days{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
.tg-days .chip{user-select:none;border:1px solid #2a3a58;border-radius:999px;padding:6px 10px;display:inline-flex;align-items:center;gap:6px;background:var(--panel-2,#0e1520)}
.tg-days .chip input{accent-color:var(--accent,#6cb6ff)}
.btn{display:inline-block;border-radius:10px;border:1px solid #2a3a58;background:linear-gradient(180deg,#122037,#0c1524);color:#dbe9ff;padding:9px 14px;font-weight:700;text-decoration:none;cursor:pointer}
.btn:hover{filter:brightness(1.08)}
.btn-primary{background:linear-gradient(180deg,#1860cc,#0f3b8f);border-color:#1b4ea4}
.btn-secondary{background:linear-gradient(180deg,#1a2739,#121b2b)}
.btn-outline{background:transparent}
.tg-table{width:100%;border-collapse:separate;border-spacing:0 6px}
.tg-table th{color:var(--muted,#9db1c9);font-weight:700;text-align:left;padding:6px 10px}
.tg-table td{background:var(--panel,#101a27);border:1px solid var(--border,#223149);padding:10px;border-left:none;border-right:none}
.tg-table tr td:first-child{border-radius:12px 0 0 12px;border-left:1px solid var(--border,#223149)}
.tg-table tr td:last-child{border-radius:0 12px 12px 0;border-right:1px solid var(--border,#223149)}
.badge{display:inline-block;border-radius:999px;padding:4px 8px;border:1px solid #2a3a58;background:var(--panel-2,#0e1520);font-size:12px}
.badge.ok{border-color:#285b2f;background:#11361a;color:#a8f0b3}
.badge.off{border-color:#5b2f2f;background:#361111;color:#f0b3b3}
</style>

<div class="tg-page">
  <h1 style="margin:0 0 14px 0">Telegram — рассылки</h1>
  <?php if($flash_err): ?><div class="alert alert-danger"><?= e($flash_err) ?></div><?php endif; ?>
  <?php if($flash_ok):  ?><div class="alert alert-success"><?= e($flash_ok) ?></div><?php endif; ?>

  <div class="tg-grid">
    <!-- Workers: send now -->
    <section class="tg-card">
      <h3>Чаты работников — отправить сейчас</h3>
      <form method="post">
        <?= csrf_field_safe() ?><input type="hidden" name="scope" value="drop">
        <textarea class="tg-textarea" name="text" placeholder="Текст для всех чатов работников..."></textarea>
        <div class="tg-row tg-switch" style="margin-top:8px">
          <label><input type="checkbox" name="only_inwork_now" value="1"> Только InWork</label>
          <label><input type="checkbox" name="only_waiting_now" value="1"> Только Waiting</label>
          <div class="grow"></div>
          <button class="btn btn-primary" name="send_now" value="1">Отправить</button>
        </div>
      </form>
    </section>

    <!-- Team: send now -->
    <section class="tg-card">
      <h3>Чаты команды — отправить сейчас</h3>
      <form method="post">
        <?= csrf_field_safe() ?><input type="hidden" name="scope" value="team">
        <textarea class="tg-textarea" name="text" placeholder="Текст для чатов команды..."></textarea>
        <div class="tg-row tg-switch" style="margin-top:8px">
          <label><input type="checkbox" name="only_inwork_now" value="1"> Только InWork</label>
          <label><input type="checkbox" name="only_waiting_now" value="1"> Только Waiting</label>
          <div class="grow"></div>
          <button class="btn btn-primary" name="send_now" value="1">Отправить</button>
        </div>
      </form>
    </section>

    <!-- Workers: by timer -->
    <section class="tg-card">
      <h3>Чаты работников — по таймеру</h3>
      <form method="post">
        <?= csrf_field_safe() ?><input type="hidden" name="scope" value="drop">
        <textarea class="tg-textarea" name="message" placeholder="Ежедневное сообщение для чатов работников..."></textarea>
        <div class="tg-row tg-time" style="margin-top:10px">
          <div class="t-sub tg-note">Время (до 3 раз в день, формат HH:MM):</div>
          <input type="text" name="time1" placeholder="09:00">
          <input type="text" name="time2" placeholder="13:30">
          <input type="text" name="time3" placeholder="18:00">
        </div>
        <div class="tg-row tg-switch" style="margin-top:8px">
          <label><input type="checkbox" name="only_inwork" value="1"> Только InWork</label>
        </div>
        <div class="tg-note" style="margin-top:6px">Дни недели (если не выбрать — будет каждый день):</div>
        <div class="tg-days">
          <?php $dmap=[['mon','Пн'],['tue','Вт'],['wed','Ср'],['thu','Чт'],['fri','Пт'],['sat','Сб'],['sun','Вс']]; ?>
          <?php foreach($dmap as $d): ?>
            <label class="chip"><input type="checkbox" name="dow_<?= $d[0] ?>" value="1"> <?= $d[1] ?></label>
          <?php endforeach; ?>
        </div>
        <div class="tg-actions">
          <input type="hidden" name="create_timer" value="1">
          <button type="submit" class="btn btn-primary">Создать таймер</button>
        </div>
      </form>
    </section>

    <!-- Team: by timer -->
    <section class="tg-card">
      <h3>Чаты команды — по таймеру</h3>
      <form method="post">
        <?= csrf_field_safe() ?><input type="hidden" name="scope" value="team">
        <textarea class="tg-textarea" name="message" placeholder="Ежедневное сообщение для чатов команды..."></textarea>
        <div class="tg-row tg-time" style="margin-top:10px">
          <div class="t-sub tg-note">Время (до 3 раз в день, формат HH:MM):</div>
          <input type="text" name="time1" placeholder="09:00">
          <input type="text" name="time2" placeholder="13:30">
          <input type="text" name="time3" placeholder="18:00">
        </div>
        <div class="tg-row tg-switch" style="margin-top:8px">
          <label><input type="checkbox" name="only_inwork" value="1"> Только InWork</label>
        </div>
        <div class="tg-note" style="margin-top:6px">Дни недели (если не выбрать — будет каждый день):</div>
        <div class="tg-days">
          <?php $dmap=[['mon','Пн'],['tue','Вт'],['wed','Ср'],['thu','Чт'],['fri','Пт'],['sat','Сб'],['sun','Вс']]; ?>
          <?php foreach($dmap as $d): ?>
            <label class="chip"><input type="checkbox" name="dow_<?= $d[0] ?>" value="1"> <?= $d[1] ?></label>
          <?php endforeach; ?>
        </div>
        <div class="tg-actions">
          <input type="hidden" name="create_timer" value="1">
          <button type="submit" class="btn btn-primary">Создать таймер</button>
        </div>
      </form>
    </section>
  </div><!-- /tg-grid -->

  <div class="tg-card" style="margin-top:18px">
    <div class="tg-row" style="justify-content:space-between;align-items:center">
      <h3 style="margin:0">Таймеры</h3>
      <a class="btn btn-secondary" href="?run_timers=1" target="_blank">Запустить проверку сейчас</a>
    </div>
    <div style="margin-top:10px;overflow:auto">
      <table class="tg-table">
        <tr>
          <th>ID</th>
          <th>Область</th>
          <th>Сообщение</th>
          <th>Время</th>
          <th>Дни</th>
          <th>Фильтр</th>
          <th>Статус</th>
          <th></th>
        </tr>
        <?php foreach($timers as $t):
          $days = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс']; $mask=(int)($t['mask']??127); $dd=[];
          for($i=0;$i<7;$i++){ if(($mask & (1<<$i))!==0) $dd[]=$days[$i]; }
          $when = implode(', ', array_filter([substr((string)$t['time1'],0,5)?:null, substr((string)$t['time2'],0,5)?:null, substr((string)$t['time3'],0,5)?:null]));
        ?>
        <tr>
          <td><?= (int)$t['id'] ?></td>
          <td><?= h($t['scope']==='team'?'Команда':'Работники') ?></td>
          <td><?= nl2br(h((string)$t['message'])) ?></td>
          <td><?= h($when) ?></td>
          <td><?= h(implode(' ', $dd)) ?></td>
          <td><?= ((int)$t['only_inwork']===1)?'Только InWork':'Все' ?></td>
          <td><?= ((int)$t['active']===1)?'<span class="badge ok">Активен</span>':'<span class="badge off">Пауза</span>' ?></td>
          <td>
            <form method="post" style="display:inline-block;margin-right:6px">
              <?= csrf_field_safe() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <input type="hidden" name="active" value="<?= ((int)$t['active']===1)?0:1 ?>">
              <button class="btn btn-secondary" name="toggle" value="1"><?= ((int)$t['active']===1)?'Пауза':'Включить' ?></button>
            </form>
            <form method="post" style="display:inline-block" onsubmit="return confirm('Удалить таймер #<?= (int)$t['id'] ?>?')">
              <?= csrf_field_safe() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="btn btn-outline" name="delete" value="1">Удалить</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
</div>
<!--TG_PAGE_END-->
<!--TG_PAGE_END-->
<?php
$content_html = ob_get_clean();

// ---------- Layout integration (robust) ----------
$title = 'Telegram — рассылки';
$layout_php    = __DIR__.'/_layout.php';
$layout_header = __DIR__.'/_layout_header.php';
$layout_footer = __DIR__.'/_layout_footer.php';

// expose common variables for various layout conventions
$PAGE_TITLE = $TITLE = $title;
$content = $CONTENT = $_content = $__content = $content_html;
// also expose a callable, if layout expects a renderer
$renderContent = $render_body = $render_page = $__render = function() use ($content_html){ echo $content_html; };

if (is_file($layout_php)) {
  // capture layout output to check if it included our content markers
  ob_start();
  include $layout_php;
  $rendered = ob_get_clean();
  if (strpos($rendered, '<!--TG_PAGE_START-->') !== false) {
    echo $rendered; // layout printed our content
  } else {
    // inject our content before </body> to keep sidebar/header and avoid empty page
    if (stripos($rendered, '</body>') !== false) {
      $rendered = preg_replace('~</body>~i', $content_html.'</body>', $rendered, 1);
      echo $rendered;
    } else {
      // last resort
      echo $rendered;
      echo $content_html;
    }
  }
} else {
  // header/footer pattern
  if (is_file($layout_header)) require $layout_header;
  echo $content_html;
  if (is_file($layout_footer)) require $layout_footer;
}
?>