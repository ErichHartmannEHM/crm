<?php
require_once __DIR__.'/../lib/db.php';
$id=(int)($_GET['id']??0); $row=db_exec('SELECT * FROM card_statements WHERE id=?',[$id])->fetch();
if(!$row){ http_response_code(404); exit('Not found'); }
$path=__DIR__.'/../storage/uploads/statements/'.$row['file_path'];
if(!is_file($path)){ http_response_code(404); exit('Missing'); }
$mime=$row['mime']?:'application/octet-stream';
header('Content-Type: '.$mime); header('Content-Disposition: inline; filename="'.basename($row['file_name_orig']).'"'); readfile($path);