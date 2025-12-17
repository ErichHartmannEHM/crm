<?php
// bot_clients/diag_send.php — мини-диагностика отправки (печатает причины ошибок)
require_once __DIR__.'/../lib/db.php';
$token = getenv('TG_CLIENT_BOT_TOKEN');
if (!$token) { $f = __DIR__.'/token.php'; if (file_exists($f)) { require $f; $token = $tg_clients_token ?? ''; } }
if (!$token) { die("Нет токена TG_CLIENT_BOT_TOKEN и bot_clients/token.php\n"); }

function tg($m,$p){ global $token;
  $ch=curl_init('https://api.telegram.org/bot'.$token.'/'.$m);
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$p,CURLOPT_RETURNTRANSFER=>true]);
  $res=curl_exec($ch); $err=curl_error($ch); curl_close($ch);
  return $res? json_decode($res,true): ['ok'=>false,'description'=>$err];
}

$limit = isset($_GET['limit']) ? max(1,(int)$_GET['limit']) : 5;
$text  = $_GET['text'] ?? "Диагностика";
$st = db()->prepare("
  SELECT chat_id
  FROM client_telegram_chats
  WHERE is_active=1 AND type IN ('group','supergroup')
  GROUP BY chat_id
  ORDER BY MAX(last_seen) DESC
  LIMIT :L
");
$st->bindValue(':L', $limit, PDO::PARAM_INT);
$st->execute();

header('Content-Type: text/plain; charset=utf-8');
$i=0;
while ($r=$st->fetch(PDO::FETCH_ASSOC)) {
  $i++;
  $resp = tg('sendMessage', ['chat_id'=>$r['chat_id'], 'text'=>$text, 'parse_mode'=>'HTML']);
  echo "#$i chat_id={$r['chat_id']} => ";
  echo $resp['ok']? "OK\n" : ("ERR: ".$resp['description']."\n");
}
