<?php
// bot_clients/upload_chats_to_server.php
// Заливает JSON с чатами (результат scan_local_common.php) на сервер в seed_import.php.
//
// Пример:
//   php upload_chats_to_server.php \
//       --file=chats_common.json \
//       --url=https://service-ref.sbs/bot_clients/seed_import.php
//
// По умолчанию файл: ./chats_common.json, URL: https://service-ref.sbs/bot_clients/seed_import.php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Этот скрипт нужно запускать из консоли (php-cli).\n");
    exit(1);
}

$opts = getopt('', [
    'file::',
    'url::',
]);

$file = isset($opts['file']) ? $opts['file'] : __DIR__ . '/chats_common.json';
$url  = isset($opts['url'])  ? $opts['url']  : 'https://service-ref.sbs/bot_clients/seed_import.php';

if (!is_file($file)) {
    fwrite(STDERR, "Файл не найден: {$file}\n");
    exit(1);
}

$data = file_get_contents($file);
if ($data === false || $data === '') {
    fwrite(STDERR, "Не удалось прочитать файл или файл пустой: {$file}\n");
    exit(1);
}

$context = stream_context_create([
    'http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => $data,
        'ignore_errors' => true,
        'timeout'       => 120,
    ],
]);

fwrite(STDOUT, "Отправляем данные на {$url}...\n");
$result = @file_get_contents($url, false, $context);
if ($result === false) {
    $err = error_get_last();
    fwrite(STDERR, "Ошибка HTTP-запроса: " . ($err['message'] ?? 'unknown') . "\n");
    exit(1);
}

fwrite(STDOUT, "Ответ сервера:\n");
fwrite(STDOUT, $result . "\n");

exit(0);
