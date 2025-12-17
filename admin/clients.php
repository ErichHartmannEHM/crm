<?php
// admin/clients.php — добавлен статус по каналам и группам (v10)
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
auth_require(); auth_require_admin();

$title='Клиенты — Telegram-рассылки'; $active='clients';

if (!function_exists('db_exec')) { function db_exec($sql,$p=[]){ $st=db()->prepare($sql); $st->execute($p); return $st; } }
if (!function_exists('db_one')) { function db_one($sql,$p=[]){ $st=db_exec($sql,$p); $row=$st->fetch(PDO::FETCH_ASSOC); return $row ?: null; } }

db_exec("CREATE TABLE IF NOT EXISTS client_telegram_chats (
  chat_id BIGINT NOT NULL,
  thread_id INT NULL,
  type VARCHAR(32) NOT NULL,
  title VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (chat_id, thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function tg_token(){
  $t = getenv('TG_CLIENT_BOT_TOKEN'); if ($t) return $t;
  $f = __DIR__.'/../bot_clients/token.php';
  if (file_exists($f)) { require $f; if (!empty($tg_clients_token)) return $tg_clients_token; }
  return '';
}
function tg_api($m,$p){
  $t=tg_token(); if(!$t) return ['ok'=>false,'description'=>'no token'];
  $ch=curl_init('https://api.telegram.org/bot'.$t.'/'.$m);
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$p,CURLOPT_RETURNTRANSFER=>true]);
  $res=curl_exec($ch); $err=curl_error($ch); curl_close($ch);
  return $res? json_decode($res,true): ['ok'=>false,'description'=>$err];
}

if (isset($_GET['reactivate']) && $_GET['reactivate']=='1') { db_exec("UPDATE client_telegram_chats SET is_active=1"); header('Location: /admin/clients.php'); exit; }

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['send_now'])) {
  $text = trim($_POST['text'] ?? ''); $pm = $_POST['parse_mode'] ?? 'HTML'; $test3=!empty($_POST['test3']);
  if ($text==='') { header('Content-Type: text/plain; charset=utf-8'); echo "Пустой текст"; exit; }

  $st = db()->prepare("
    SELECT chat_id
    FROM client_telegram_chats
    WHERE is_active=1 AND type IN ('group','supergroup') AND chat_id < 0
    GROUP BY chat_id
    ORDER BY MAX(last_seen) DESC
  "); $st->execute();

  $ok=0; $bad=0; $count=0; $deactivated=0; $samples=[]; $S_MAX=10;
  while ($r=$st->fetch(PDO::FETCH_ASSOC)) {
    $count++; $cid=$r['chat_id'];
    $params=['chat_id'=>$cid,'text'=>$text]; if($pm!=='') $params['parse_mode']=$pm;
    $resp = tg_api('sendMessage',$params);
    if (!empty($resp['ok'])) $ok++;
    else {
      $bad++; $desc=$resp['description'] ?? 'unknown error';
      if (count($samples)<$S_MAX) $samples[]="chat_id=$cid → $desc";
      if (preg_match('~(Forbidden|kicked|not found|have no rights|CHAT_WRITE_FORBIDDEN|bot was blocked)~i', $desc)) {
        db_exec("UPDATE client_telegram_chats SET is_active=0 WHERE chat_id=:id", [':id'=>$cid]); $deactivated++;
      }
    }
    usleep(350000);
    if ($test3 && ($ok+$bad)>=3) break;
  }

  header('Content-Type: text/html; charset=utf-8');
  echo "<p>Отправлено: $ok, ошибок: $bad, получателей (уникальных): $count, деактивировано проблемных: $deactivated</p>";
  if ($samples) echo "<h3>Первые ошибки:</h3><pre>".htmlspecialchars(implode("\n",$samples))."</pre>";
  echo '<p><a href="/admin/clients.php">Назад</a></p>'; exit;
}

$g = db_one("SELECT COUNT(DISTINCT chat_id) AS c FROM client_telegram_chats WHERE is_active=1 AND type IN ('group','supergroup') AND chat_id < 0");
$c = db_one("SELECT COUNT(DISTINCT chat_id) AS c FROM client_telegram_chats WHERE type='channel'");
$groups_total = (int)($g['c'] ?? 0); $channels_total = (int)($c['c'] ?? 0);

?>
<?php require __DIR__.'/_layout.php'; ?>
<div class="content">
  <h1><?=$title?></h1>

  <div class="card">
    <h3>Статус</h3>
    <ul>
      <li>Группы/супергруппы (уникальные): <b><?=$groups_total?></b></li>
      <li>Каналы (broadcast) в базе: <b><?=$channels_total?></b> (в рассылке не участвуют по умолчанию)</li>
      <li>Токен: <?= tg_token() ? '<span style="color:limegreen">OK</span>' : '<span style="color:#e33">НЕ ЗАДАН</span>' ?></li>
      <li><a href="/bot_clients/get_webhook_info.php" target="_blank">Проверить getWebhookInfo</a> · <a href="/bot_clients/set_webhook.php" target="_blank">Переустановить webhook</a> · <a href="/bot_clients/compact_table.php" target="_blank">Компактировать БД</a> · <a href="/admin/clients.php?reactivate=1" onclick="return confirm('Сделать все чаты активными?')">Сделать все активными</a> · <a href="/bot_clients/seed_web.php?include_channels=1" target="_blank">Импортировать «общие» (включая каналы)</a></li>
    </ul>
  </div>

  <div class="card">
    <h3>Отправка сообщения</h3>
    <form method="post">
      <textarea name="text" rows="5" style="width:100%" placeholder="Текст сообщения" required></textarea>
      <div style="margin:8px 0;">
        <label>Parse mode:
          <select name="parse_mode">
            <option value="HTML">HTML</option>
            <option value="Markdown">Markdown</option>
            <option value="">Без форматирования</option>
          </select>
        </label>
      </div>
      <button type="submit" name="send_now" value="1">Отправить сейчас</button>
      <button type="submit" name="send_now" value="1" onclick="this.form.test3.value=1;">Тест (3 чата)</button>
      <input type="hidden" name="test3" value="">
    </form>
    <p style="color:#666;margin-top:6px">Отправляем по уникальным <b>group/supergroup</b> (chat_id &lt; 0). Каналы можно импортировать для учёта, но рассылка туда идёт только если отдельно включать.</p>
  </div>
</div>
