<?php
// admin/scanner/cron_scan.php — пробежка по всем заявкам (можно дергать из CRON/по кнопке)
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/RefundScanner.php';
auth_require_admin();
header('Content-Type: text/plain; charset=utf-8');
$res = RefundScanner::scanAll(null);
echo "checked={$res['checked']} errors={$res['errors']}\n";
foreach ($res['byWorker'] as $w) echo "worker={$w['name']} count={$w['count']}\n";
