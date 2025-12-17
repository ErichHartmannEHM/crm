<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/settings.php';

header('Content-Type: text/plain; charset=utf-8');

function clients_token(): ?string {
  try { $cfg = setting_get('clients.bot_token'); if ($cfg) return (string)$cfg; } catch (Throwable $e) {}
  $file = __DIR__.'/token.php'; if (is_file($file)) { require_once $file; if (defined('CLIENTS_BOT_TOKEN')) return (string)CLIENTS_BOT_TOKEN; }
  $env = getenv('TG_CLIENT_BOT_TOKEN'); if ($env) return (string)$env;
  return null;
}

$token = clients_token();
if (!$token) { http_response_code(500); echo "No clients bot token"; exit; }

// Build webhook URL
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
$proto = $isHttps ? 'https://' : 'http://';
$host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
$base = rtrim($proto.$host, '/');
$webhookUrl = $base . '/bot_clients/webhook.php';

// Secret (optional, but recommended)
$secret = (string)(setting_get('tg_clients_webhook_secret') ?? '');
if ($secret === '') {
  $secret = bin2hex(random_bytes(16));
  try { setting_set('tg_clients_webhook_secret', $secret); } catch (Throwable $e) {}
}

$payload = [
  'url' => $webhookUrl,
  'secret_token' => $secret,
  'drop_pending_updates' => true,
  'allowed_updates' => ['message','my_chat_member','chat_member'],
];

$ch = curl_init('https://api.telegram.org/bot'.$token.'/setWebhook');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_POST=>true,
  CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
  CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
  CURLOPT_CONNECTTIMEOUT=>8,
  CURLOPT_TIMEOUT=>20,
]);
$resp = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);

echo "setWebhook for {$webhookUrl}\n";
echo "HTTP {$code}\n";
echo $resp ? $resp : ("ERROR: ".$err);
