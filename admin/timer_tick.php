<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php'; 
// Only admins should trigger timer from browser; webhook will also trigger it server-side.
auth_require(); auth_require_admin();

require_once __DIR__ . '/../lib/timer_runtime.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $rep = tg_timer_tick(false);
    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage();
}
