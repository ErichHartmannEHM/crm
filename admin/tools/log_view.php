<?php
declare(strict_types=1);
date_default_timezone_set(getenv('APP_TZ') ?: 'Europe/Kiev');
$APP = dirname(__DIR__, 1);

$logDir = $APP . '/storage/logs/telegram';
$today = $logDir . '/broadcast_' . date('Ymd') . '.log';
$files = glob($logDir . '/broadcast_*.log');
rsort($files);
$target = is_file($today) ? $today : ($files[0] ?? null);

?><!doctype html><meta charset="utf-8"><title>Telegram logs</title>
<style>body{font-family:system-ui,Arial,sans-serif;background:#0b1220;color:#d7e3ff;padding:20px}
pre{background:#0f1a33;padding:12px;border-radius:6px;white-space:pre-wrap;}</style>
<h3>Логи рассылок</h3>
<p>Файл: <code><?=htmlspecialchars($target ?? '—')?></code></p>
<pre><?php
if ($target && is_file($target)) echo htmlspecialchars(file_get_contents($target));
else echo "Логов пока нет.";
?></pre>
<p><a style="color:#8ab4ff" href="timer_diag.php">← назад</a></p>
