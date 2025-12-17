<?php
// bot_clients/seed_web.php — импорт чатов (v18.1, фиксы синтаксиса PHP)
// - Скан по двум папкам: folder_id=0 (основные) и folder_id=1 (архив)
// - Параметры: mode=scan|common, include_channels=1, scan_limit=5..100 (по умолчанию 10), refresh=сек (0=выкл)
// - Нет проверок членства бота (быстро и без доп. запросов); проверка произойдёт на рассылке
// - IPC полностью заглушён; первым делом подключаем madeline-*.phar, если есть
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
auth_require(); auth_require_admin();

@ini_set('memory_limit','512M');
@ini_set('max_execution_time','55');
@set_time_limit(55);

$mode = $_GET['mode'] ?? $_POST['mode'] ?? 'scan';
$include_channels = (isset($_GET['include_channels']) && $_GET['include_channels']=='1') || (isset($_POST['include_channels']) && $_POST['include_channels']=='1');
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$scan_limit = max(5, min(100, (int)($_GET['scan_limit'] ?? 10)));
$refresh = (int)($_GET['refresh'] ?? 7);

$session_file = __DIR__ . '/seed_user.session';
$state_file   = __DIR__ . '/seed_scan_state.json';
if (isset($_GET['reset'])) { if (file_exists($session_file)) @unlink($session_file); if (file_exists($state_file)) @unlink($state_file); }

// ---- Глушим IPC до подключения
if (!defined('MADELINE_DISABLE_IPC')) define('MADELINE_DISABLE_IPC', true);
if (!defined('MADELINE_SOCKETS_DISABLE')) define('MADELINE_SOCKETS_DISABLE', true);
@putenv('MADELINE_DISABLE_IPC=1');
@putenv('MADELINE_SOCKETS_DISABLE=1');

// ---- Подключаем библиотеку (phar в приоритете)
$lib = null;
$ph = glob(__DIR__.'/madeline*.phar');
if ($ph) $lib = $ph[0];
elseif (file_exists(__DIR__.'/madeline.php')) $lib = __DIR__.'/madeline.php';
if (!$lib) { die('Загрузите madeline-*.phar или madeline.php в /bot_clients/.'); }

$prev_disp = ini_get('display_errors'); $prev_rep = error_reporting();
@ini_set('display_errors','0'); @error_reporting(E_ERROR|E_PARSE);
require_once $lib;
@ini_set('display_errors',$prev_disp); @error_reporting($prev_rep);

if (class_exists('Revolt\EventLoop')) {
  try { \Revolt\EventLoop::setErrorHandler(static function(Throwable $e){ /* подавляем внутренние ошибки IPC */ }); } catch (Throwable $e) {}
}

// ---- База
if (!function_exists('db_exec')) { function db_exec($sql,$p=[]) { $st=db()->prepare($sql); $st->execute($p); return $st; } }
db_exec("CREATE TABLE IF NOT EXISTS client_telegram_chats (
  chat_id BIGINT NOT NULL,
  thread_id INT NULL,
  type VARCHAR(32) NOT NULL,
  title VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (chat_id, thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ---- Поля формы
$bot_username = $_POST['bot_username'] ?? $_GET['bot_username'] ?? 'illuminatorMarketing_bot';
$api_id   = (int)($_POST['api_id'] ?? $_GET['api_id'] ?? getenv('TG_API_ID') ?: 0);
$api_hash = $_POST['api_hash'] ?? $_GET['api_hash'] ?? getenv('TG_API_HASH') ?: '';
$phone    = $_POST['phone'] ?? '';
$code     = $_POST['code'] ?? '';
$password = $_POST['password'] ?? '';
$step     = $_POST['step'] ?? 'start';
$error=''; $inline_error=''; $message='';

function insert_chat($id,$type,$title){
  db_exec("INSERT INTO client_telegram_chats (chat_id, thread_id, type, title, is_active, last_seen)
           VALUES (:id, NULL, :type, :title, 1, NOW())
           ON DUPLICATE KEY UPDATE type=VALUES(type), title=VALUES(title), is_active=1, last_seen=NOW()", [':id'=>$id, ':type'=>$type, ':title'=>$title]);
}
function disable_ipc_compat($settings){
  try {
    if (method_exists($settings, 'getIpc')) {
      $ipc = $settings->getIpc();
      foreach (['setEnableIpc','setEnabled','setIsEnabled','setEnable'] as $m) if (method_exists($ipc,$m)) { $ipc->$m(false); return; }
      if (method_exists($settings,'setIpc')) {
        $ipcObj = new \danog\MadelineProto\Settings\Ipc();
        foreach (['setEnabled','setEnableIpc','setIsEnabled','setEnable'] as $m) if (method_exists($ipcObj,$m)) $ipcObj->$m(false);
        $settings->setIpc($ipcObj);
      }
    }
  } catch (Throwable $e) {}
}
function mp_make_api($session_file,$api_id,$api_hash){
  $haveSession = file_exists($session_file);
  $haveKeys = $api_id && $api_hash;
  if (class_exists('\danog\MadelineProto\Settings')) {
    $settings = new \danog\MadelineProto\Settings();
    disable_ipc_compat($settings);
    try { if (method_exists($settings,'getLogger')) $settings->getLogger()->setLevel(\danog\MadelineProto\Logger::ERROR); } catch (Throwable $e) {}
    if ($haveSession && !$haveKeys) return new \danog\MadelineProto\API($session_file, $settings);
    if (!$haveKeys) throw new Exception('Нужно указать API_ID и API_HASH (https://my.telegram.org/apps).');
    try {
      if (method_exists($settings,'getAppInfo')) $settings->getAppInfo()->setApiId($api_id)->setApiHash($api_hash);
      elseif (method_exists($settings,'setAppInfo')) { $app = new \danog\MadelineProto\Settings\AppInfo(); if (method_exists($app,'setApiId')) $app->setApiId($api_id); if (method_exists($app,'setApiHash')) $app->setApiHash($api_hash); $settings->setAppInfo($app); }
    } catch (Throwable $e) {}
    return new \danog\MadelineProto\API($session_file, $settings);
  } else {
    $settings = ['app_info'=>['api_id'=>$api_id,'api_hash'=>$api_hash],'ipc'=>['enable_ipc'=>false]];
    if ($haveSession && !$haveKeys) $settings = ['ipc'=>['enable_ipc'=>false]];
    return new \danog\MadelineProto\API($session_file, $settings);
  }
}

// ---- Шаги авторизации
$need_api = !file_exists($session_file) && (!$api_id || !$api_hash);
if ($step==='start' && $need_api) $step='ask_api';

try {
  $MP = null; $me=null;
  if ($step!=='ask_api') {
    $MP = mp_make_api($session_file,$api_id,$api_hash);
    try { $me=$MP->getSelf(); } catch (Throwable $e) {}
    if (!$me) $step='ask_phone';
  }

  if ($step==='ask_phone' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$phone) $inline_error='Укажите телефон.';
    else { $MP->phoneLogin($phone); $step='ask_code'; }
  } elseif ($step==='ask_code' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$code) $inline_error='Введите код.';
    else { $res=$MP->completePhoneLogin($code); if ($res==='ACCOUNT_PASSWORD') $step='ask_2fa'; else $step='import'; }
  } elseif ($step==='ask_2fa' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$password) $inline_error='Введите пароль двухфакторной аутентификации.';
    else { $MP->complete2faLogin($password); $step='import'; }
  }

  if ($step==='start' && !$need_api) $step='import';

  if ($step==='import') {
    if (!$MP) $MP = mp_make_api($session_file,$api_id,$api_hash);

    if ($mode==='scan') {
      // ---- Постраничный скан по папкам: 0 — основные, 1 — архив
      $state = ['folder'=>0,'offset_date'=>0,'offset_id'=>0,'offset_peer'=>['_'=>'inputPeerEmpty'],'page'=>0,'done'=>false,'imported'=>0];
      if (file_exists($state_file)) { $tmp=json_decode(@file_get_contents($state_file), true); if (is_array($tmp)) $state=array_merge($state,$tmp); }
      if ($action==='resetscan') { @unlink($state_file); $state=['folder'=>0,'offset_date'=>0,'offset_id'=>0,'offset_peer'=>['_'=>'inputPeerEmpty'],'page'=>0,'done'=>false,'imported'=>0]; }

      if (!$state['done']) {
        $state['page']++;
        $args = [
          'offset_date' => $state['offset_date'],
          'offset_id'   => $state['offset_id'],
          'offset_peer' => $state['offset_peer'],
          'limit'       => $scan_limit,
          'hash'        => 0
        ];
        // пытаемся явно указать папку (если версия поддерживает)
        try { $args['folder_id'] = $state['folder']; } catch (Throwable $e) {}
        $res = $MP->messages->getDialogs($args);
        $dialogs = $res['dialogs'] ?? [];
        $chats   = $res['chats'] ?? [];
        $msgs    = $res['messages'] ?? [];
        if (!$dialogs) {
          if ($state['folder']===0) { // переключаемся на архив
            $state['folder']=1; $state['offset_date']=0; $state['offset_id']=0; $state['offset_peer']=['_'=>'inputPeerEmpty']; $state['page']=0;
          } else {
            $state['done']=true;
          }
        } else {
          $msgById=[]; foreach ($msgs as $m) if (isset($m['id'])) $msgById[$m['id']]=$m;
          foreach ($chats as $c) {
            $id=(int)($c['id'] ?? 0); if ($id<=0) continue;
            $isChannel = (($c['_'] ?? '')==='channel'); $isMega = $isChannel && !empty($c['megagroup']); $title=$c['title'] ?? '';
            if ($isChannel && !$isMega) { if (!$include_channels) continue; $chat_id=(int)('-100'.$id); $type='channel'; insert_chat($chat_id,$type,$title); $state['imported']++; continue; }
            if ($isChannel && $isMega) { $chat_id=(int)('-100'.$id); $type='supergroup'; } else { $chat_id=-$id; $type='group'; }
            insert_chat($chat_id,$type,$title); $state['imported']++;
          }
          $last = end($dialogs);
          if ($last) {
            $state['offset_id'] = (int)($last['top_message'] ?? 0);
            $m = $msgById[$state['offset_id']] ?? null;
            $state['offset_date'] = (int)($m['date'] ?? 0);
            $state['offset_peer'] = $last['peer'] ?? ['_'=>'inputPeerEmpty'];
          } else {
            if ($state['folder']===0) { $state['folder']=1; $state['offset_date']=0; $state['offset_id']=0; $state['offset_peer']=['_'=>'inputPeerEmpty']; $state['page']=0; }
            else $state['done']=true;
          }
        }
        file_put_contents($state_file, json_encode($state, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
      }

      $message = $state['done']
        ? ("Полный скан завершён. Папка: основные+архив. Страниц всего: ".$state['page'].", добавлено/обновлено: ".$state['imported'].", каналы: ".($include_channels?'да':'нет').".")
        : ("Папка: ".($state['folder']===0?'основные':'архив').". Обработана страница №".$state['page'].", всего импортировано: ".$state['imported'].".");

      // Авто‑переход на следующую страницу (редко, чтобы не ловить анти‑DDoS)
      if (!$state['done'] && $refresh>0) {
        $qs = http_build_query(['mode'=>'scan','include_channels'=>$include_channels?1:0,'scan_limit'=>$scan_limit,'refresh'=>$refresh,'action'=>'run']);
        header('Refresh: '.$refresh.'; url=?'.$qs);
      }

    } else {
      // ---- Быстрый импорт «общих»
      $imported=0; $pages=0; $max_id=0; $seen_ids=[];
      do {
        $pages++;
        $batch = $MP->messages->getCommonChats(['user_id'=>'@'.$bot_username,'max_id'=>$max_id,'limit'=>100]);
        $chats = $batch['chats'] ?? [];
        if (!$chats) break;
        foreach ($chats as $c) {
          $id=(int)($c['id'] ?? 0); if ($id<=0) continue;
          if (isset($seen_ids[$id])) continue; $seen_ids[$id]=true;
          $title=$c['title'] ?? '';
          $isChannel=(($c['_'] ?? '')==='channel'); $isMega=$isChannel && !empty($c['megagroup']);
          if ($isChannel && !$isMega) { if (!$include_channels) continue; $chat_id=(int)('-100'.$id); $type='channel'; }
          elseif ($isChannel && $isMega) { $chat_id=(int)('-100'.$id); $type='supergroup'; }
          else { $chat_id=-$id; $type='group'; }
          insert_chat($chat_id,$type,$title); $imported++; $max_id=$id;
        }
      } while (count($chats)>=100 && $pages<300);
      $message = "Импорт «общих» завершён. Страниц: $pages, добавлено/обновлено: $imported. Каналы: ".($include_channels?'да':'нет').".";
    }
  }

} catch (Throwable $e) {
  $msg=$e->getMessage();
  if (stripos($msg,'SESSION_PASSWORD_NEEDED')!==false) { $step='ask_2fa'; $inline_error='Введите пароль двухфакторной аутентификации.'; $error=''; }
  elseif (preg_match('/FLOOD_WAIT_(\d+)/',$msg,$m)) { $sec=(int)$m[1]; $min=intdiv($sec,60); $rem=$sec%60; $error = "Telegram ограничил попытки. Подождите {$min} мин {$rem} сек и повторите."; }
  else { $error=$msg; }
}

?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><title>Клиенты — импорт чатов (<?=$mode==='scan'?'полный скан':'общие'?>)</title>
<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;max-width:860px;margin:24px auto;padding:0 12px}
.card{border:1px solid #ddd;border-radius:8px;padding:16px;margin:12px 0}
label{display:block;margin:8px 0}input[type=text],input[type=number],input[type=password]{width:100%;padding:8px}
button{padding:8px 12px;cursor:pointer}.err{border-color:#e33;color:#a00}</style></head><body>
<h1>Импорт чатов — <?=$mode==='scan'?'полный скан диалогов':'общие чаты'?></h1>
<?php if (!empty($error)): ?><div class="card err"><b>Ошибка:</b> <?=htmlspecialchars($error)?></div><?php endif; ?>

<div class="card" style="background:#f9f9f9">
  <form method="get">
    <label>
      Режим:
      <select name="mode">
        <option value="scan" <?=$mode==='scan'?'selected':''?>>Полный скан диалогов (основные + архив)</option>
        <option value="common" <?=$mode==='common'?'selected':''?>>Быстрый: «общие чаты» с ботом</option>
      </select>
    </label>
    <label><input type="checkbox" name="include_channels" value="1" <?=$include_channels?'checked':''?>> Импортировать также каналы (broadcast)</label>
    <?php if ($mode==='scan'): ?>
      <label>Размер страницы (scan_limit, 5–100): <input type="number" name="scan_limit" value="<?=$scan_limit?>" min="5" max="100"></label>
      <label>Авто‑переход (refresh, сек; 0 — выкл): <input type="number" name="refresh" value="<?=$refresh?>" min="0" max="60"></label>
    <?php else: ?>
      <label>Имя пользователя бота (без @): <input type="text" name="bot_username" value="<?=htmlspecialchars($bot_username)?>"></label>
    <?php endif; ?>
    <button type="submit">Применить</button>
    · <a href="?reset=1">Начать заново</a>
    <?php if ($mode==='scan'): ?> · <a href="?mode=scan&action=resetscan">Сбросить прогресс скана</a><?php endif; ?>
  </form>
</div>

<?php if ($step==='import'): ?>
  <div class="card"><p><?=htmlspecialchars($message ?: 'Готово.')?></p>
    <p><a href="/admin/clients.php">Перейти в «Клиенты»</a></p>
  </div>
<?php elseif ($step==='ask_api' || $step==='ask_phone' || $step==='ask_code' || $step==='ask_2fa'): ?>
  <!-- Формы авторизации выше -->
<?php endif; ?>

<p style="color:#555">Режим: <b><?=htmlspecialchars($mode)?></b> · Библиотека: <code><?=htmlspecialchars(basename($lib))?></code> · Файл сессии: <code><?=htmlspecialchars(basename($session_file))?></code> · scan_limit=<?=$scan_limit?> · refresh=<?=$refresh?></p>
</body></html>
