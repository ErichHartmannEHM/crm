<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/telegram.php';

header('Content-Type: text/html; charset=utf-8');

$act = isset($_GET['a']) ? strtolower((string)$_GET['a']) : 'get';
$secret = trim((string)($_GET['secret'] ?? ''));

// Если передали ?secret=..., сохраним в настройках
if ($secret !== '') {
  try { setting_set('tg_webhook_secret', $secret); } catch (Throwable $e) {}
}

// Сформировать URL до webhook.php
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'service-ref.sbs';
$url    = $scheme . '://' . $host . '/bot/webhook.php';

function out_json($data) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

if ($act === 'set') {
  $fields = ['url' => $url];
  $s = (string)setting_get('tg_webhook_secret', '');
  if ($s !== '') $fields['secret_token'] = $s;

  $err = null; $raw = null;
  $resp = telegram_api('setWebhook', $fields, $raw, $err);
  out_json(['ok' => (bool)($resp['ok'] ?? false), 'url' => $url, 'secret' => $s, 'resp' => $resp, 'error' => $err]);
}

if ($act === 'delete') {
  $err = null; $raw = null;
  $resp = telegram_api('deleteWebhook', [], $raw, $err);
  out_json(['ok' => (bool)($resp['ok'] ?? false), 'resp' => $resp, 'error' => $err]);
}

if ($act === 'get') {
  $err = null; $raw = null;
  $resp = telegram_api('getWebhookInfo', [], $raw, $err);
  out_json(['ok' => (bool)($resp['ok'] ?? true), 'resp' => $resp, 'error' => $err, 'computed_url' => $url]);
}

// Help
?>
<!doctype html>
<meta charset="utf-8">
<title>Webhook tools</title>
<style>
  body{font-family:system-ui,Segoe UI,Roboto,Arial;margin:24px;background:#0f1115;color:#e8ecf1}
  a.btn{display:inline-block;margin:6px 8px;padding:10px 14px;border:1px solid #334;padding:10px 14px;border-radius:10px;color:#e8ecf1;text-decoration:none;background:#1f2632}
  small{color:#8a8f98}
  input{padding:8px;border-radius:8px;border:1px solid #334;background:#11151c;color:#e8ecf1}
  code{background:#11151c;padding:2px 6px;border-radius:6px}
</style>
<h2>Telegram Webhook Tools</h2>
<p>Авто-URL для вебхука: <code><?=htmlspecialchars($url, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')?></code></p>
<p>
  <a class="btn" href="?a=set">✅ Установить webhook</a>
  <a class="btn" href="?a=get">ℹ️ Проверить (getWebhookInfo)</a>
  <a class="btn" href="?a=delete">🗑 Удалить webhook</a>
</p>

<form method="get" style="margin-top:18px">
  <input type="hidden" name="a" value="set">
  <label>Secret token: <input name="secret" placeholder="необязательно"></label>
  <button class="btn" type="submit">Сохранить секрет и установить webhook</button>
  <br><small>Если секрет будет указан, он сохранится в настройках как <code>tg_webhook_secret</code> и будет требоваться в заголовке Telegram.</small>
</form>
