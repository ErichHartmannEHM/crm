<?php
// bot_clients/get_webhook_info.php
require_once __DIR__.'/token.php';
$u = 'https://api.telegram.org/bot' . $tg_clients_token . '/getWebhookInfo';
$ch = curl_init($u);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);
header('Content-Type: application/json; charset=utf-8');
echo $res ?: json_encode(['ok'=>false,'error'=>$err], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
