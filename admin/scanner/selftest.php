<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/Db.php';
require_once __DIR__ . '/classes/RefundScanner.php';
auth_require();
header('Content-Type: text/plain; charset=utf-8');
echo "=== Scanner self-test ===\n";
try { Db::pdo()->query('SELECT 1'); echo "DB: OK\n"; } catch (Throwable $e) { echo "DB ERROR: ".$e->getMessage()."\n"; exit; }
$tbl = Db::workersTable();
echo "Workers table detected: ".($tbl ?: 'NONE')."\n";
$list = RefundScanner::listWorkers();
echo "Workers count: ".count($list)."\n";
foreach (array_slice($list,0,10) as $w) echo "- {$w['id']}: {$w['name']}\n";
echo "\nAPI checks (GET):\n";
$base = dirname($_SERVER['REQUEST_URI']).'/api.php';
echo " workers: {$base}?action=workers\n";
echo " list:    {$base}?action=list\n";
echo " history: {$base}?action=history&id=TEST_ID\n";
