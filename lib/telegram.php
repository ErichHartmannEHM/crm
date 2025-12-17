<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

// Global toggle to mute ALL admin-chat notifications
if (!defined('ADMIN_NOTIFICATIONS_ENABLED')) { define('ADMIN_NOTIFICATIONS_ENABLED', false); }

/** Resolve token/admin chat */
function _tg_resolve_bot_token(): ?string {
    try {
        $cfg = app_config();
        if (!empty($cfg['telegram']['bot_token'])) return (string)$cfg['telegram']['bot_token'];
        if (!empty($cfg['security']['telegram_bot_token'])) return (string)$cfg['security']['telegram_bot_token'];
    } catch (Throwable $e) {}
    $env = getenv('TG_BOT_TOKEN'); if ($env) return (string)$env;
    $set = setting_get('telegram_bot_token', null); if ($set) return (string)$set;
    return null;
}
function _tg_resolve_admin_chat(): ?string {
    $set = setting_get('admin_chat_id', null); if ($set) return (string)$set;
    try {
        $cfg = app_config();
        if (!empty($cfg['telegram']['admin_chat_id'])) return (string)$cfg['telegram']['admin_chat_id'];
        if (!empty($cfg['security']['telegram_admin_chat_id'])) return (string)$cfg['security']['telegram_admin_chat_id'];
    } catch (Throwable $e) {}
    $env = getenv('TG_ADMIN_CHAT_ID'); if ($env) return (string)$env;
    return null;
}
function _tg_mask_token(?string $t): string {
    if (!$t) return '(empty)';
    $len = strlen($t); return $len<=10?str_repeat('*',$len):substr($t,0,6).'...'.substr($t,-4);
}

/** Generic Telegram API call (JSON) */
function telegram_api(string $method, array $params = [], ?array &$raw = null, ?string &$error = null): ?array {
    // Deduplicate duplicate sendMessage calls within a single request (same chat_id + text + thread)
    static $_tg_api_dedup = [];
    if ($method === 'sendMessage' && isset($params['chat_id']) && isset($params['text'])) {
        $tid = isset($params['message_thread_id']) ? (int)$params['message_thread_id'] : 0;
        $key = (string)$params['chat_id'].'#'.$tid.'#'.sha1(trim((string)$params['text']));
        if (isset($_tg_api_dedup[$key])) {
            $raw = $_tg_api_dedup[$key]['raw'];
            $error = null;
            return $_tg_api_dedup[$key]['resp'];
        }
    }
    $error = null; $raw = null;
    $token = _tg_resolve_bot_token();
    if (!$token) { $error = 'No bot token'; return null; }
    $url = "https://api.telegram.org/bot{$token}/".$method;
    $json = json_encode($params, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) { $error = 'cURL: '.curl_error($ch); curl_close($ch); return null; }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $j = json_decode((string)$resp, true);
        $raw = $j;
        if (!is_array($j)) { $error = 'Bad JSON (HTTP '.$code.')'; return null; }
        if (empty($j['ok'])) { $error = 'TG error '.($j['error_code']??'').': '.($j['description']??'unknown'); }
        if ($method === 'sendMessage' && isset($params['chat_id']) && isset($params['text'])) { $_tg_api_dedup[$key] = ['resp'=>$j,'raw'=>$raw]; }
        return $j;
    } else {
        $ctx = stream_context_create([ 'http' => ['method'=>'POST','header'=>"Content-Type: application/json",'content'=>$json,'timeout'=>10] ]);
        $resp = @file_get_contents($url,false,$ctx);
        if ($resp === false) { $error = 'HTTP error'; return null; }
        $j = json_decode((string)$resp, true);
        $raw = $j;
        if (!is_array($j)) { $error = 'Bad JSON'; return null; }
        if (empty($j['ok'])) { $error = 'TG error '.($j['error_code']??'').': '.($j['description']??'unknown'); }
        if ($method === 'sendMessage' && isset($params['chat_id']) && isset($params['text'])) { $_tg_api_dedup[$key] = ['resp'=>$j,'raw'=>$raw]; }
        return $j;
    }
}

/** Send message; auto-handle migrate_to_chat_id */
function telegram_send(string $chat_id, string $text, ?string &$error = null): bool {
    // Работник sends to admin chat when muted
    if (defined('ADMIN_NOTIFICATIONS_ENABLED') && !ADMIN_NOTIFICATIONS_ENABLED) {
        $admin = _tg_resolve_admin_chat();
        if ($admin && (string)$chat_id === (string)$admin) { $error = 'admin notifications disabled'; return true; }
    }

    $error = null; $raw = null;
    $j = telegram_api('sendMessage', ['chat_id'=>$chat_id,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true], $raw, $error);
    if (is_array($j) && !empty($j['ok'])) return true;

    // try migrate_to_chat_id
    if (is_array($raw) && !empty($raw['parameters']['migrate_to_chat_id'])) {
        $newChat = (string)$raw['parameters']['migrate_to_chat_id'];
        // resend
        $error2 = null; $raw2 = null;
        $j2 = telegram_api('sendMessage', ['chat_id'=>$newChat,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true], $raw2, $error2);
        if (is_array($j2) && !empty($j2['ok'])) {
            // persist new admin chat if old matched
            try {
                $admin = _tg_resolve_admin_chat();
                if ($admin && $admin === $chat_id) { setting_set('admin_chat_id', $newChat); }
            } catch (Throwable $e) {}
            return true;
        } else {
            $error = $error2 ?: $error;
            return false;
        }
    }
    return false;
}

/* ===== NEW: multipart helpers (sendDocument / sendPhoto) ===== */

/**
 * Low-level multipart POST to Telegram (files). Returns decoded json.
 * $files = ['document' => ['/path/file','mime','filename']]
 */
function _telegram_api_multipart(string $method, array $fields, array $files, ?array &$raw = null, ?string &$error = null): ?array {
    $error = null; $raw = null;
    $token = _tg_resolve_bot_token();
    if (!$token) { $error = 'No bot token'; return null; }
    if (!function_exists('curl_init')) { $error = 'cURL not available'; return null; }

    $url = "https://api.telegram.org/bot{$token}/".$method;

    // Build POST with CURLFile
    $post = $fields;
    foreach ($files as $name => $info) {
        [$path, $mime, $fname] = [$info[0] ?? null, $info[1] ?? null, $info[2] ?? null];
        if (!$path || !is_file($path)) { $error = "file '$name' missing"; return null; }
        if (!$mime)  $mime  = mime_content_type($path) ?: 'application/octet-stream';
        if (!$fname) $fname = basename($path);
        $post[$name] = new CURLFile($path, $mime, $fname);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { $error = 'cURL: '.curl_error($ch); curl_close($ch); return null; }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $j = json_decode((string)$resp, true);
    $raw = $j;
    if (!is_array($j)) { $error = 'Bad JSON (HTTP '.$code.')'; return null; }
    if (empty($j['ok'])) { $error = 'TG error '.($j['error_code']??'').': '.($j['description']??'unknown'); }
    return $j;
}

/** Отправка документа (CSV/XLSX/PDF и т.д.) с автопочинкой migrate_to_chat_id */
function telegram_send_document(string $chat_id, string $file_path, string $caption = '', ?string &$error = null, ?string $file_name = null, ?string $mime = null): bool {
    $error = null; $raw = null;
    $fields = ['chat_id' => $chat_id, 'caption' => $caption, 'parse_mode' => 'HTML'];
    $files  = ['document' => [$file_path, $mime, $file_name]];
    $j = _telegram_api_multipart('sendDocument', $fields, $files, $raw, $error);
    if (is_array($j) && !empty($j['ok'])) return true;

    // migrate_to_chat_id support
    if (is_array($raw) && !empty($raw['parameters']['migrate_to_chat_id'])) {
        $newChat = (string)$raw['parameters']['migrate_to_chat_id'];
        $error2 = null; $raw2 = null;
        $fields['chat_id'] = $newChat;
        $j2 = _telegram_api_multipart('sendDocument', $fields, $files, $raw2, $error2);
        if (is_array($j2) && !empty($j2['ok'])) {
            try {
                $admin = _tg_resolve_admin_chat();
                if ($admin && $admin === $chat_id) { setting_set('admin_chat_id', $newChat); }
            } catch (Throwable $e) {}
            return true;
        } else {
            $error = $error2 ?: $error;
            return false;
        }
    }
    return false;
}

/** Отправка фото (jpg/png) с подписью и migrate-починкой */
function telegram_send_photo(string $chat_id, string $file_path, string $caption = '', ?string &$error = null, ?string $file_name = null, ?string $mime = null): bool {
    $error = null; $raw = null;
    $fields = ['chat_id' => $chat_id, 'caption' => $caption, 'parse_mode' => 'HTML'];
    $files  = ['photo' => [$file_path, $mime ?: 'image/jpeg', $file_name]];
    $j = _telegram_api_multipart('sendPhoto', $fields, $files, $raw, $error);
    if (is_array($j) && !empty($j['ok'])) return true;

    if (is_array($raw) && !empty($raw['parameters']['migrate_to_chat_id'])) {
        $newChat = (string)$raw['parameters']['migrate_to_chat_id'];
        $error2 = null; $raw2 = null;
        $fields['chat_id'] = $newChat;
        $j2 = _telegram_api_multipart('sendPhoto', $fields, $files, $raw2, $error2);
        if (is_array($j2) && !empty($j2['ok'])) {
            try {
                $admin = _tg_resolve_admin_chat();
                if ($admin && $admin === $chat_id) { setting_set('admin_chat_id', $newChat); }
            } catch (Throwable $e) {}
            return true;
        } else {
            $error = $error2 ?: $error;
            return false;
        }
    }
    return false;
}

/** Уведомление баеру по card_id */
function telegram_notify_buyer_by_card(int $card_id, string $text, ?string &$error = null): bool {
    try {
        $row = db_row("SELECT b.telegram_chat_id FROM cards c JOIN buyers b ON b.id=c.buyer_id WHERE c.id=?", [$card_id]);
        if (!$row || empty($row['telegram_chat_id'])) { $error = 'buyer chat not set'; return false; }
        return telegram_send((string)$row['telegram_chat_id'], $text, $error);
    } catch (Throwable $e) { $error = $e->getMessage(); return false; }
}

/** Уведомление админу */
function telegram_notify_admin(string $text, ?string &$error = null): bool {
    // Mute admin notifications if globally disabled
    if (defined('ADMIN_NOTIFICATIONS_ENABLED') && !ADMIN_NOTIFICATIONS_ENABLED) { $error = 'admin notifications disabled'; return true; }

    $chat = _tg_resolve_admin_chat();
    if (!$chat) { $error = 'admin chat not set'; return false; }
    return telegram_send((string)$chat, $text, $error);
}

/** Diagnostics with migrate suggestion */
function telegram_diag_full(): array {
    $token = _tg_resolve_bot_token();
    $admin = _tg_resolve_admin_chat();
    $out = ['token_masked'=>_tg_mask_token($token),'admin_chat'=>(string)($admin??'')];
    $err = null; $raw = null;
    $me = telegram_api('getMe', [], $raw, $err);
    $out['getMe_ok'] = (int)!empty($me['ok']); $out['getMe_err'] = $out['getMe_ok']? '' : (string)($err??'');

    if ($admin) {
        $err = null; $raw = null;
        $gc = telegram_api('getChat', ['chat_id'=>$admin], $raw, $err);
        $out['getChat_ok'] = (int)!empty($gc['ok']); $out['getChat_err'] = $out['getChat_ok']? '' : (string)($err??'');
    } else { $out['getChat_ok']=0; $out['getChat_err']='admin chat not set'; }

    $err = null; $raw = null;
    $ok = telegram_send($admin?:'0', '🔧 Диагностика: '.date('Y-m-d H:i:s'), $err);
    $out['send_ok'] = $ok?1:0; $out['send_err'] = (string)($err??'');

    if (!$ok && is_array($raw) && !empty($raw['parameters']['migrate_to_chat_id'])) {
        $out['migrate_to_chat_id'] = (string)$raw['parameters']['migrate_to_chat_id'];
    }
    return $out;
}
