<?php
// admin/scanner/api.php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/classes/RefundScanner.php';
require_once __DIR__ . '/classes/ProxyManager.php';

auth_require();

$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

function api_out($data): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    switch ($action) {
        case 'workers':
            api_out(['items' => RefundScanner::listWorkers()]);

        case 'list':
            $workerId = isset($_GET['worker_id']) && $_GET['worker_id'] !== '' ? (int)$_GET['worker_id'] : null;
            $statusRaw = $_GET['status'] ?? null;
            $status = ($statusRaw === '' || $statusRaw === null) ? null : $statusRaw;
            $rows = RefundScanner::listRequests($workerId, $status);
            $counters = RefundScanner::counters();
            api_out(['items' => $rows, 'counters' => $counters]);

        case 'history':
            $id = trim($_GET['id'] ?? '');
            api_out(['items' => RefundScanner::getHistory($id)]);

        case 'proxies_list':
            api_out(ProxyManager::list());

        case 'export':
            $workerId = isset($_GET['worker_id']) && $_GET['worker_id'] !== '' ? (int)$_GET['worker_id'] : null;
            $statusRaw = $_GET['status'] ?? null;
            $status = ($statusRaw === '' || $statusRaw === null) ? null : $statusRaw;
            $rows = RefundScanner::listRequests($workerId, $status);

            header('Content-Type: text/csv; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('Content-Disposition: attachment; filename="scanner_export.csv"');

            $fh = fopen('php://output', 'w');
            fputcsv($fh, ['request_id', 'worker', 'status', 'last_dt']);
            foreach ($rows as $r) {
                fputcsv($fh, [
                    $r['request_id'] ?? '',
                    $r['worker_name'] ?? '',
                    $r['last_status'] ?? '',
                    $r['last_dt'] ?? '',
                ]);
            }
            exit;

        case 'import':
            $idsStr = trim($input['ids'] ?? '');
            $ids = $idsStr === '' ? [] : preg_split('~[\s,;]+~u', $idsStr, -1, PREG_SPLIT_NO_EMPTY);
            $wid = array_key_exists('worker_id', $input) && $input['worker_id'] !== '' && $input['worker_id'] !== null
                ? (int)$input['worker_id']
                : null;
            api_out(RefundScanner::importRequests($ids, $wid));

        case 'reassign':
            $ids = isset($input['ids']) && is_array($input['ids']) ? $input['ids'] : [];
            $wid = array_key_exists('worker_id', $input) && $input['worker_id'] !== '' && $input['worker_id'] !== null
                ? (int)$input['worker_id']
                : null;
            api_out(['moved' => RefundScanner::reassign($ids, $wid)]);

        case 'scan_one':
            $id = trim($input['id'] ?? '');
            api_out(RefundScanner::scanOne($id));

        case 'scan_all':
            $wid = array_key_exists('worker_id', $input) && $input['worker_id'] !== '' && $input['worker_id'] !== null
                ? (int)$input['worker_id']
                : null;
            api_out(RefundScanner::scanAll($wid));

        case 'proxy_save':
            $id = isset($input['id']) && $input['id'] !== '' ? (int)$input['id'] : null;
            $data = [
                'title'              => trim($input['title'] ?? ''),
                'proxy_url'          => trim($input['proxy_url'] ?? ''),
                'refresh_url'        => trim($input['refresh_url'] ?? ''),
                'assigned_worker_id' => isset($input['assigned_worker_id']) && $input['assigned_worker_id'] !== ''
                    ? (int)$input['assigned_worker_id']
                    : null,
                'batch_limit'        => (int)($input['batch_limit'] ?? 10),
                'refresh_wait_sec'   => (int)($input['refresh_wait_sec'] ?? 20),
                'active'             => (int)($input['active'] ?? 1),
            ];
            $newId = ProxyManager::save($id, $data);
            api_out(['id' => $newId]);

        case 'proxy_delete':
            ProxyManager::delete((int)($input['id'] ?? 0));
            api_out(['ok' => 1]);

        case 'proxy_refresh':
            $id = (int)($input['id'] ?? 0);
            $list = ProxyManager::list();
            $p = null;
            foreach ($list as $row) {
                if ((int)$row['id'] === $id) { $p = $row; break; }
            }
            if (!$p) {
                api_out(['ok' => false, 'error' => 'not found']);
            }
            ProxyManager::refresh($p);
            api_out(['ok' => 1]);

        case 'proxy_test':
            $id = (int)($input['id'] ?? 0);
            $list = ProxyManager::list();
            $p = null;
            foreach ($list as $row) {
                if ((int)$row['id'] === $id) { $p = $row; break; }
            }
            if (!$p) {
                api_out(['ok' => false, 'error' => 'not found']);
            }
            api_out(ProxyManager::test($p));

        case 'diag':
            $row = Db::one("SELECT COUNT(*) AS c FROM scanner_requests");
            $cnt = (int)($row['c'] ?? 0);
            api_out(['ok' => true, 'scanner_requests_count' => $cnt]);

        default:
            api_out(['error' => 'unknown_action']);
    }
} catch (Throwable $e) {
    api_out(['error' => $e->getMessage()]);
}
