<?php
// bot_clients/seed_bot.php — импорт чатов напрямую ОТ ИМЕНИ БОТА (без user/2FA)
// Требования: в /bot_clients/ должен лежать madeline.php или madeline-*.phar и token.php с $tg_clients_token.
// Опции: ?include_channels=1 — также импортировать каналы (в рассылке по умолчанию не участвуют).
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/auth.php';
auth_require(); auth_require_admin();

$include_channels = isset($_GET['include_channels']) && $_GET['include_channels']=='1';

// 1) Токен бота
$token = getenv('TG_CLIENT_BOT_TOKEN');
if (!$token) { $f = __DIR__.'/token.php'; if (file_exists($f)) { require $f; $token = $tg_clients_token ?? ''; } }
if (!$token) { http_response_code(500); die('Не задан токен TG_CLIENT_BOT_TOKEN и нет bot_clients/token.php'); }

// 2) MadelineProto
$lib = null;
if (file_exists(__DIR__.'/madeline.php')) $lib = __DIR__.'/madeline.php';
else { $ph = glob(__DIR__.'/madeline*.phar'); if ($ph) $lib = $ph[0]; }
if (!$lib) { http_response_code(500); die('Загрузите madeline.php или madeline-*.phar в /bot_clients/.'); }

$prev_disp = ini_get('display_errors'); $prev_rep = error_reporting();
@ini_set('display_errors','0'); @error_reporting(E_ERROR|E_PARSE);
require_once $lib;
@ini_set('display_errors',$prev_disp); @error_reporting($prev_rep);

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

$session = __DIR__.'/seed_bot.session';

// 3) Вход как БОТ
$MP = new \danog\MadelineProto\API($session);
$me = null;
try {
    $me = $MP->getSelf();
    if (empty($me) || empty($me['bot'])) {
        $MP->botLogin($token);
        $me = $MP->getSelf();
    }
} catch (Throwable $e) {
    $MP->botLogin($token);
    $me = $MP->getSelf();
}
if (empty($me) || empty($me['bot'])) { http_response_code(500); die('Не удалось авторизоваться ботом.'); }

$imported=0; $pages=0;
$offset_date = 0;
$offset_id = 0;
$offset_peer = ['_' => 'inputPeerEmpty'];
$limit = 100;

// 4) Перебор диалогов бота (messages.getDialogs)
do {
    $pages++;
    $args = [
        'offset_date' => $offset_date,
        'offset_id' => $offset_id,
        'offset_peer' => $offset_peer,
        'limit' => $limit,
        'hash' => 0
    ];
    $res = $MP->messages->getDialogs($args);
    $chats = $res['chats'] ?? [];
    $dialogs = $res['dialogs'] ?? [];
    $msgs = $res['messages'] ?? [];

    if (!$dialogs) break;

    // мапа id => message для получения даты
    $msgById = [];
    foreach ($msgs as $m) { if (isset($m['id'])) $msgById[$m['id']] = $m; }

    foreach ($chats as $c) {
        $id = (int)($c['id'] ?? 0);
        if ($id<=0) continue;
        $isChannel = (($c['_'] ?? '') === 'channel');
        $isMega = $isChannel && !empty($c['megagroup']);
        $title = $c['title'] ?? '';

        if ($isChannel && !$isMega) {
            if (!$include_channels) continue;   // каналы импортируем только по флажку
            $chat_id = (int)('-100'.$id);
            $type = 'channel';
        } elseif ($isChannel && $isMega) {
            $chat_id = (int)('-100'.$id);
            $type = 'supergroup';
        } else {
            $chat_id = -$id;
            $type = 'group';
        }
        db_exec("INSERT INTO client_telegram_chats (chat_id, thread_id, type, title, is_active, last_seen)
                 VALUES (:id, NULL, :type, :title, 1, NOW())
                 ON DUPLICATE KEY UPDATE type=VALUES(type), title=VALUES(title), is_active=1, last_seen=NOW()",
                [':id'=>$chat_id, ':type'=>$type, ':title'=>$title]);
        $imported++;
    }

    // пагинация — по последнему диалогу (peer + top_message)
    $last = end($dialogs);
    if (!$last) break;
    $offset_id = (int)($last['top_message'] ?? 0);
    $m = $msgById[$offset_id] ?? null;
    $offset_date = (int)($m['date'] ?? 0);
    $peer = $last['peer'] ?? ['_' => 'inputPeerEmpty'];
    // для offset_peer используем тот же peer; MadelineProto сам нормализует
    $offset_peer = $peer;

} while (count($dialogs) >= $limit && $pages < 200);

header('Content-Type: text/plain; charset=utf-8');
echo "Готово. Импортировано (upsert): $imported. Страниц: $pages. Включая каналы: ".($include_channels?'да':'нет')."\n";
echo "Перейдите в /admin/clients.php, чтобы увидеть обновлённые счётчики.\n";
