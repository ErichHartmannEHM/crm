<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$token = null;
// try token.php
$file = __DIR__.'/token.php'; if (is_file($file)) { require_once $file; if (defined('CLIENTS_BOT_TOKEN')) $token = (string)CLIENTS_BOT_TOKEN; }
if (!$token) { $env = getenv('TG_CLIENT_BOT_TOKEN'); if ($env) $token = (string)$env; }
if (!$token) {
  // settings requires DB, but we keep this file independent
  echo json_encode(['ok'=>false,'error'=>'No token; define CLIENTS_BOT_TOKEN or TG_CLIENT_BOT_TOKEN']); exit;
}

$resp = @file_get_contents('https://api.telegram.org/bot'.$token.'/getWebhookInfo');
echo $resp ?: json_encode(['ok'=>false,'error'=>'no response']);
