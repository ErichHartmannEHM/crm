<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/settings.php';

header('Content-Type: text/plain; charset=utf-8');

function cfg_token(): ?string {
  try {
    $cfg = app_config();
    return $cfg['telegram']['bot_token'] ?? ($cfg['security']['telegram_bot_token'] ?? null);
  } catch (Throwable $e) { return null; }
}

function http($url, array $opt = []): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, $opt + [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $resp, $err];
}

// Token
$token = cfg_token();
if (!$token) { http_response_code(500); exit("No bot token in config.\n"); }

// Optional override: ?domain=bot.yourdomain.com
$forced = isset($_GET['domain']) ? trim((string)$_GET['domain']) : '';

// Compute webhook URL
$host   = $forced !== '' ? $forced : ($_SERVER['HTTP_HOST'] ?? 'localhost');
$scheme = 'https'; // Telegram requires HTTPS
$webhookUrl = $scheme.'://'.$host.'/bot/webhook.php';

// Secret: load or create
$secret = (string)setting_get('tg_webhook_secret', '');
if ($secret === '') {
  $secret = bin2hex(random_bytes(16));
  setting_set('tg_webhook_secret', $secret);
}

$base = "https://api.telegram.org/bot{$token}";

// deleteWebhook (best effort)
list($c1,$r1,$e1) = http($base.'/deleteWebhook');
echo "deleteWebhook: ".($r1 ?: ("HTTP {$c1}; ".($e1?:'n/a')))."\n";

// Prepare payload
$payload = json_encode([
  'url' => $webhookUrl,
  'secret_token' => $secret,
  'max_connections' => 40,
  'allowed_updates' => ['message','callback_query']
], JSON_UNESCAPED_UNICODE);

// setWebhook with retry on 429
$attempts = 0; $max = 5;
$last = null;
while ($attempts < $max) {
  $attempts++;
  list($c2,$r2,$e2) = http($base.'/setWebhook', [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload]);
  $last = [$c2,$r2,$e2];
  $out  = $r2 ?: ("HTTP {$c2}; ".($e2?:'n/a'));
  echo "Attempt {$attempts}: setWebhook for {$webhookUrl}\nsecret_token: {$secret}\n{$out}\n\n";
  if ($c2 != 429) break;
  $retry = 2;
  $j = json_decode((string)$r2, true);
  if (isset($j['parameters']['retry_after']) && is_numeric($j['parameters']['retry_after'])) {
    $retry = max(1, (int)$j['parameters']['retry_after']);
  }
  $retry *= $attempts;
  @sleep($retry);
}

list($code,$resp,$err) = $last;
if ($code === 200) echo "DONE: webhook set.\n";
else echo "FAILED: ".($resp ?: ("HTTP {$code}; ".($err?:'n/a')))."\n";
