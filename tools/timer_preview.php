<?php
declare(strict_types=1);
// Usage: php tools/timer_preview.php 11 [--inwork]
$ROOT = dirname(__DIR__);
require_once $ROOT . '/cron/telegram_dispatch.php'; // reuse functions without sending

if ($argc < 2) { echo "Usage: php tools/timer_preview.php <schedule_id>\n"; exit(1); }
$schId = intval($argv[1]);
$onlyInwork = in_array('--inwork', $argv, true);

// read schedule
$stmt = $pdo->prepare("SELECT * FROM tg_broadcast_schedules WHERE id=:id");
$stmt->execute([':id'=>$schId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo "Schedule not found\n"; exit(1); }

echo "Schedule #{$row['id']} scope={$row['scope']} only_inwork={$row['only_inwork']} time1={$row['time1']} time2={$row['time2']} time3={$row['time3']} mask={$row['mask']}\n";

$chats = ($row['scope'] === 'drop')
    ? find_drop_chats($pdo, boolval(intval($row['only_inwork'])))
    : find_team_chats($pdo);

echo "Chats matched: ".count($chats)."\n";
foreach ($chats as $c) {
    echo "- chat_id={$c['chat_id']} thread_id=" . ($c['thread_id'] ?? 'null') . "\n";
}
