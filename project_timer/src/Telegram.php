<?php
declare(strict_types=1);
namespace Timer;
class Telegram {
    private string $token; private string $apiUrl; private int $timeout;
    public function __construct(string $token, string $apiUrl='https://api.telegram.org', int $timeout=10){ $this->token=$token; $this->apiUrl=rtrim($apiUrl,'/'); $this->timeout=$timeout; }
    public function sendMessage(int|string $chatId, string $text, array $extra=[]): array {
        $payload=array_merge(['chat_id'=>(string)$chatId,'text'=>$text,'disable_web_page_preview'=>true],$extra);
        $ch=curl_init($this->apiUrl.'/bot'.$this->token.'/sendMessage'); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>$this->timeout,CURLOPT_TIMEOUT=>$this->timeout,CURLOPT_HTTPHEADER=>['Expect:']]); $resp=curl_exec($ch); $errno=curl_errno($ch); $err=curl_error($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if($errno) throw new \RuntimeException("cURL error: {$errno} {$err}"); if($code<200||$code>=300) throw new \RuntimeException("HTTP {$code}: {$resp}");
        $data=json_decode((string)$resp,true); if(!is_array($data)||!($data['ok']??false)) throw new \RuntimeException('Telegram API error: '.(is_string($resp)?$resp:'unknown')); return $data;
    }
}
