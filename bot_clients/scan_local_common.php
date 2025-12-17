<?php
// bot_clients/scan_local_common.php
// Локальный CLI-скрипт для импорта «общих» чатов с ботом через MadelineProto.
//
// Запускать ТОЛЬКО из консоли, пример:
//
//   php scan_local_common.php \
//       --bot=illuminatorMarketing_bot \
//       --api-id=123456 \
//       --api-hash=abcdef0123456789abcdef0123456789 \
//       --phone=+79990000000
//
// Первый запуск попросит код из Telegram (и пароль 2FA, если включена).
// Результат работы — файл chats_common.json рядом со скриптом.
// Этот JSON затем можно отправить на сервер в bot_clients/seed_import.php.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Этот скрипт нужно запускать из консоли (php-cli).\n");
    exit(1);
}

// ---------- Параметры CLI ----------

$opts = getopt('', [
    'bot::',               // имя бота без @
    'api-id::',
    'api-hash::',
    'phone::',
    'session::',
    'output::',
    'include-channels::',  // 1 = включать каналы (они всё равно seed_import сейчас игнорирует)
]);

$bot_username      = isset($opts['bot']) ? $opts['bot'] : 'illuminatorMarketing_bot';
$api_id            = isset($opts['api-id']) ? (int)$opts['api-id'] : 0;
$api_hash          = isset($opts['api-hash']) ? $opts['api-hash'] : '';
$phone             = isset($opts['phone']) ? $opts['phone'] : '';
$session_file      = isset($opts['session']) ? $opts['session'] : __DIR__ . '/seed_user_local.session';
$out_file          = isset($opts['output']) ? $opts['output'] : __DIR__ . '/chats_common.json';
$include_channels  = isset($opts['include-channels']) && $opts['include-channels'] === '1';

function cli_readline_simple(string $prompt): string {
    fwrite(STDOUT, $prompt);
    $line = fgets(STDIN);
    if ($line === false) {
        return '';
    }
    return trim($line);
}

// ---------- Подключаем MadelineProto ----------

// Глушим IPC, как в исходном проекте, чтобы не плодить фоновые процессы
if (!defined('MADELINE_DISABLE_IPC')) {
    define('MADELINE_DISABLE_IPC', true);
}
if (!defined('MADELINE_SOCKETS_DISABLE')) {
    define('MADELINE_SOCKETS_DISABLE', true);
}
@putenv('MADELINE_DISABLE_IPC=1');
@putenv('MADELINE_SOCKETS_DISABLE=1');

// Ищем madeline-*.phar либо madeline.php в текущей папке bot_clients
$lib = null;
$phList = glob(__DIR__ . '/madeline*.phar');
if ($phList && count($phList) > 0) {
    $lib = $phList[0];
} elseif (file_exists(__DIR__ . '/madeline.php')) {
    $lib = __DIR__ . '/madeline.php';
}

if (!$lib) {
    fwrite(STDERR, "Не найден madeline-*.phar или madeline.php в каталоге bot_clients.\n");
    exit(1);
}

// Временно глушим display_errors, чтобы Madeline не засоряла вывод
$prev_disp = ini_get('display_errors');
$prev_rep  = error_reporting();
@ini_set('display_errors', '0');
@error_reporting(E_ERROR | E_PARSE);
require_once $lib;
@ini_set('display_errors', $prev_disp);
@error_reporting($prev_rep);

if (!class_exists('\\danog\\MadelineProto\\API')) {
    fwrite(STDERR, "Не найден класс danog\\MadelineProto\\API. Проверьте madeline*.phar.\n");
    exit(1);
}

// ---------- Создаём/открываем сессию ----------

$haveSession = file_exists($session_file);
$haveKeys    = $api_id && $api_hash;

if ($haveSession && !$haveKeys) {
    // Уже есть сессия — можно не указывать API ID/Hash
    $settings = ['ipc' => ['enable_ipc' => false]];
} else {
    if (!$haveKeys) {
        fwrite(STDERR, "Нужно указать --api-id и --api-hash (их можно взять на https://my.telegram.org/apps).\n");
        exit(1);
    }
    $settings = [
        'app_info' => [
            'api_id'   => $api_id,
            'api_hash' => $api_hash,
        ],
        'ipc' => [
            'enable_ipc' => false,
        ],
    ];
}

$MP = new \danog\MadelineProto\API($session_file, $settings);

// При первом запуске потребуется авторизация по телефону
if (!$haveSession) {
    if (!$phone) {
        $phone = cli_readline_simple("Введите номер телефона в формате +79990000000: ");
    }
    if (!$phone) {
        fwrite(STDERR, "Телефон не указан.\n");
        exit(1);
    }

    fwrite(STDOUT, "Отправляем код на номер {$phone}...\n");
    $MP->phoneLogin($phone);

    $code = cli_readline_simple("Введите код из Telegram/SMS: ");
    if (!$code) {
        fwrite(STDERR, "Код не указан.\n");
        exit(1);
    }

    try {
        $res = $MP->completePhoneLogin($code);
        if ($res === 'ACCOUNT_PASSWORD') {
            $pwd = cli_readline_simple("Включена двухфакторная авторизация. Введите пароль: ");
            $MP->complete2faLogin($pwd);
        }
    } catch (\Throwable $e) {
        if (stripos($e->getMessage(), 'SESSION_PASSWORD_NEEDED') !== false) {
            $pwd = cli_readline_simple("Включена двухфакторная авторизация. Введите пароль: ");
            $MP->complete2faLogin($pwd);
        } else {
            fwrite(STDERR, "Ошибка авторизации: " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    fwrite(STDOUT, "Авторизация успешно завершена. Сессия сохранена в {$session_file}\n");
}

// Проверим, что всё ок
try {
    $me = $MP->getSelf();
} catch (\Throwable $e) {
    fwrite(STDERR, "Не удалось получить данные текущего пользователя: " . $e->getMessage() . "\n");
    exit(1);
}
$me_name = $me['username'] ?? ($me['first_name'] ?? 'без имени');
fwrite(STDOUT, "Залогинены как: {$me_name}\n");

// ---------- Скан общих чатов с ботом ----------

$imported = 0;
$pages    = 0;
$max_id   = 0;
$seen_ids = [];
$result   = [];

do {
    $pages++;
    $batch = $MP->messages->getCommonChats([
        'user_id' => '@' . $bot_username,
        'max_id'  => $max_id,
        'limit'   => 100,
    ]);

    $chats = $batch['chats'] ?? [];
    if (!$chats) {
        break;
    }

    foreach ($chats as $c) {
        $id = isset($c['id']) ? (int)$c['id'] : 0;
        if ($id <= 0) {
            continue;
        }
        if (isset($seen_ids[$id])) {
            continue;
        }
        $seen_ids[$id] = true;

        $title = isset($c['title']) ? $c['title'] : '';
        $isChannel = (isset($c['_']) && $c['_'] === 'channel');
        $isMega    = $isChannel && !empty($c['megagroup']);

        if ($isChannel && !$isMega) {
            // Обычный канал — для рассылок через seed_import сейчас не используется.
            if (!$include_channels) {
                continue;
            }
            $type    = 'channel';
            $chat_id = $id; // Положительный, seed_import сам превратит в -100id, но потом отфильтрует по type.
        } elseif ($isChannel && $isMega) {
            // Мегагруппа (supergroup)
            $type    = 'supergroup';
            $chat_id = $id; // положительный id — seed_import сделает -100id
        } else {
            // Обычная группа
            $type    = 'group';
            $chat_id = $id; // положительный id — seed_import сделает -id
        }

        $result[] = [
            'chat_id' => $chat_id,
            'type'    => $type,
            'title'   => $title,
        ];
        $imported++;
        $max_id = $id;
    }

} while (count($chats) >= 100 && $pages < 300);

if (!file_put_contents($out_file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
    fwrite(STDERR, "Не удалось записать файл {$out_file}\n");
    exit(1);
}

fwrite(STDOUT, "Готово. Найдено чатов: {$imported}, страниц: {$pages}.\n");
fwrite(STDOUT, "JSON сохранён в файле: {$out_file}\n");
fwrite(STDOUT, "Этот файл можно отправить на сервер в bot_clients/seed_import.php.\n");

exit(0);
