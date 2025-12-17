<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/settings.php';

header('Content-Type: text/plain; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(200);
  echo "ok";
  exit;
}
$EXPECTED = (string)setting_get('tg_webhook_secret', '');
$got = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($EXPECTED !== '' && $got !== $EXPECTED) {
  http_response_code(401);
  echo "forbidden";
  exit;
}
echo "ok";
