<?php
declare(strict_types=1);
echo "<pre>";
echo "__DIR__ = ".__DIR__."\n";
$w = realpath(__DIR__.'/../../cron/telegram_worker.php');
echo "WORKER PATH = ".$w."\n";
echo "exists? = ".(is_file($w)?'YES':'NO')."\n";
echo "</pre>";
