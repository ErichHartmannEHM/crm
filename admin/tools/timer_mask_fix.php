<?php
declare(strict_types=1);
date_default_timezone_set(getenv('APP_TZ') ?: 'Europe/Kiev');
$APP = dirname(__DIR__, 1);

require_once $APP . '/cron/telegram_dispatch.php';

if (isset($_GET['all'])) {
    $pdo->exec("UPDATE tg_broadcast_schedules SET mask=127 WHERE mask=0");
    echo "OK: все mask=0 → 127. <a href='timer_diag.php'>назад</a>";
    exit;
}
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$mask = isset($_GET['mask']) ? intval($_GET['mask']) : 127;
if ($id<=0) { echo "bad id"; exit; }

$stmt = $pdo->prepare("UPDATE tg_broadcast_schedules SET mask=:m WHERE id=:id");
$stmt->execute([':m'=>$mask, ':id'=>$id]);
echo "OK: #{$id} mask={$mask}. <a href='timer_diag.php'>назад</a>";
