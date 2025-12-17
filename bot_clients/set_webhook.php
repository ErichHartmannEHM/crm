<?php
// bot_clients/set_webhook.php
require_once __DIR__.'/token.php';

$host = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$url = $host . '/bot_clients/webhook.php';

$params = [
    'url' => $url,
    'allowed_updates' => json_encode(['message','my_chat_member','chat_member']),
    'max_connections' => 40,
];

$ch = curl_init('https://api.telegram.org/bot' . $tg_clients_token . '/setWebhook');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json; charset=utf-8');
echo $res ?: json_encode(['ok'=>false,'error'=>$err], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
