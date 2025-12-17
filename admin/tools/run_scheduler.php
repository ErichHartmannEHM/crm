<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
echo "=== RUN SCHEDULER DEBUG (server time) ===\n";
$roots=[realpath(__DIR__.'/..'), realpath(__DIR__.'/../..'), $_SERVER['DOCUMENT_ROOT']??null];
$roots=array_values(array_unique(array_filter($roots)));
foreach($roots as $i=>$r) echo "root[$i]: $r\n";
$runner=null;
foreach($roots as $r){
  $try=$r.'/cron/telegram_scheduler.php';
  echo "check: $try ... ".(is_file($try)?"FOUND":"not found")."\n";
  if(is_file($try)){$runner=$try;break;}
}
if(!$runner){ echo "ERROR: scheduler not found\n"; http_response_code(500); exit; }
$bins=['/usr/local/bin/php','/usr/bin/php','/opt/alt/php83/usr/bin/php','/opt/alt/php82/usr/bin/php', PHP_BINARY];
foreach($bins as $bin){
  if($bin && is_file($bin)){ echo "PHP BIN: $bin\n\n";
    passthru(escapeshellcmd($bin).' '.escapeshellarg($runner).' --debug 2>&1');
    exit;
  }
}
echo "No PHP binary found"; http_response_code(500);
