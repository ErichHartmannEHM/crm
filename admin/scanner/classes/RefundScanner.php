<?php
// admin/scanner/classes/RefundScanner.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/RefundScraper.php';
require_once __DIR__ . '/ProxyManager.php';

class RefundScanner {

    public static function ensureSchema(): void {
        // requests + history
        try {
            Db::execQ("CREATE TABLE IF NOT EXISTS scanner_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_id VARCHAR(32) NOT NULL UNIQUE,
                worker_id BIGINT UNSIGNED NULL,
                last_status VARCHAR(255) NULL,
                last_dt DATETIME NULL,
                last_hash CHAR(40) NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (worker_id), INDEX (last_status), INDEX (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
        try {
            Db::execQ("CREATE TABLE IF NOT EXISTS scanner_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_id VARCHAR(32) NOT NULL,
                row_order INT NOT NULL,
                d VARCHAR(10) NULL,
                t VARCHAR(5) NULL,
                status VARCHAR(255) NULL,
                comment TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX (request_id), INDEX (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }

    public static function importRequests(array $ids, ?int $workerId): array {
        self::ensureSchema();
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids), function ($v) {
            return $v !== '';
        })));
        $added = 0;
        $exists = 0;
        foreach ($ids as $id) {
            try {
                $affected = Db::execQ(
                    "INSERT INTO scanner_requests (request_id, worker_id)
                     VALUES (:id, :w)
                     ON DUPLICATE KEY UPDATE
                        worker_id = IFNULL(worker_id, VALUES(worker_id)),
                        updated_at = NOW()",
                    [':id' => $id, ':w' => $workerId]
                );
                if ($affected === 1) {
                    $added++;
                } else {
                    $exists++;
                }
            } catch (Throwable $e) {
                $exists++;
            }
        }
        return ['added' => $added, 'exists' => $exists];
    }

    public static function reassign(array $ids, ?int $workerId): int {
        self::ensureSchema();
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids), function ($v) {
            return $v !== '';
        })));
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE scanner_requests SET worker_id = ? WHERE request_id IN ($in)";
        $params = array_merge([$workerId], $ids);
        $st = Db::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function listWorkers(): array {
        $tbl = Db::workersTable();
        if ($tbl === 'drops') {
            return Db::all(
                "SELECT id,
                        COALESCE(
                            NULLIF(CONCAT('@', tg_nick), '@'),
                            NULLIF(name, ''),
                            login
                        ) AS name
                 FROM drops
                 WHERE IFNULL(is_active,1) = 1
                 ORDER BY name"
            );
        }
        if ($tbl === 'buyers') {
            return Db::all(
                "SELECT id, name
                 FROM buyers
                 WHERE deleted_at IS NULL
                 ORDER BY name"
            );
        }
        return [];
    }

    private static function workerNameExpr(string $alias = 'r'): string {
        $tbl = Db::workersTable();
        if ($tbl === 'drops') {
            return "(SELECT COALESCE(NULLIF(CONCAT('@', tg_nick), '@'), NULLIF(name,''), login) FROM drops WHERE id = {$alias}.worker_id)";
        }
        if ($tbl === 'buyers') {
            return "(SELECT name FROM buyers WHERE id = {$alias}.worker_id)";
        }
        return "NULL";
    }

    public static function listRequests(?int $workerId = null, ?string $status = null): array {
        self::ensureSchema();
        $where = [];
        $params = [];
        if ($workerId !== null) {
            $where[] = "r.worker_id = :w";
            $params[':w'] = $workerId;
        }
        if ($status !== null && $status !== '') {
            $where[] = "r.last_status = :s";
            $params[':s'] = $status;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $nameExpr = self::workerNameExpr('r');
        $sql = "SELECT r.*, {$nameExpr} AS worker_name
                FROM scanner_requests r
                {$whereSql}
                ORDER BY r.updated_at DESC, r.request_id";
        return Db::all($sql, $params);
    }

    public static function getHistory(string $requestId): array {
        self::ensureSchema();
        return Db::all(
            "SELECT d, t, status, comment
             FROM scanner_history
             WHERE request_id = :id
             ORDER BY row_order",
            [':id' => $requestId]
        );
    }

    public static function counters(): array {
        self::ensureSchema();
        $byStatus = Db::all(
            "SELECT last_status AS status, COUNT(*) AS cnt
             FROM scanner_requests
             GROUP BY last_status
             ORDER BY cnt DESC"
        );
        $nameExpr = self::workerNameExpr('r');
        $byWorker = Db::all(
            "SELECT r.worker_id, COUNT(*) AS cnt, {$nameExpr} AS name
             FROM scanner_requests r
             GROUP BY r.worker_id
             ORDER BY cnt DESC"
        );
        return ['byStatus' => $byStatus, 'byWorker' => $byWorker];
    }

    public static function scanOne(string $requestId): array {
        self::ensureSchema();

        $req = Db::one("SELECT * FROM scanner_requests WHERE request_id = :id", [':id' => $requestId]);
        if (!$req) {
            Db::execQ("INSERT IGNORE INTO scanner_requests (request_id) VALUES (:id)", [':id' => $requestId]);
            $req = Db::one("SELECT * FROM scanner_requests WHERE request_id = :id", [':id' => $requestId]);
        }

        $workerId = isset($req['worker_id']) ? (int)$req['worker_id'] : null;
        $proxy = ProxyManager::getByWorker($workerId);
        if ($proxy) {
            ProxyManager::incCounterAndMaybeRotate($proxy);
        }

        $data = RefundScraper::fetch($requestId, $proxy);
        if (!($data['ok'] ?? false)) {
            return ['ok' => false, 'request_id' => $requestId, 'error' => $data['error'] ?? 'UNKNOWN'];
        }

        // rewrite history
        Db::execQ("DELETE FROM scanner_history WHERE request_id = :id", [':id' => $requestId]);
        $pdo = Db::pdo();
        $ins = $pdo->prepare(
            "INSERT INTO scanner_history (request_id, row_order, d, t, status, comment)
             VALUES (:id, :ord, :d, :t, :s, :c)"
        );
        foreach ($data['rows'] as $row) {
            $ins->execute([
                ':id'  => $requestId,
                ':ord' => (int)($row['order'] ?? 0),
                ':d'   => $row['date'] ?? null,
                ':t'   => $row['time'] ?? null,
                ':s'   => $row['status'] ?? null,
                ':c'   => $row['comment'] ?? null,
            ]);
        }

        $hash = sha1(json_encode($data['rows'], JSON_UNESCAPED_UNICODE));
        Db::execQ(
            "UPDATE scanner_requests
             SET last_status = :s,
                 last_dt     = :dt,
                 last_hash   = :h,
                 updated_at  = NOW()
             WHERE request_id = :id",
            [
                ':s'  => $data['current_status'],
                ':dt' => $data['current_dt'],
                ':h'  => $hash,
                ':id' => $requestId,
            ]
        );

        return [
            'ok'             => true,
            'request_id'     => $requestId,
            'current_status' => $data['current_status'],
            'current_dt'     => $data['current_dt'],
        ];
    }

    public static function scanAll(?int $workerId = null): array {
        self::ensureSchema();
        $params = [];
        $whereSql = '';
        if ($workerId !== null) {
            $whereSql = "WHERE worker_id = :w";
            $params[':w'] = $workerId;
        }

        $list = Db::all(
            "SELECT request_id, worker_id
             FROM scanner_requests
             {$whereSql}
             ORDER BY updated_at ASC, request_id",
            $params
        );

        $res = ['checked' => 0, 'errors' => 0];
        foreach ($list as $row) {
            $out = self::scanOne($row['request_id']);
            if (!($out['ok'] ?? false)) {
                $res['errors']++;
            }
            $res['checked']++;
            usleep(250000 + rand(0, 350000)); // 0.25–0.6s between requests
        }
        return $res;
    }
}
