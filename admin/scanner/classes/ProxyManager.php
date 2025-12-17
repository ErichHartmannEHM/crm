<?php
// admin/scanner/classes/ProxyManager.php
require_once __DIR__ . '/Db.php';

class ProxyManager {

    private static function ensureSchema(): void {
        try {
            Db::execQ("CREATE TABLE IF NOT EXISTS scanner_proxies (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(120) NOT NULL,
                proxy_url VARCHAR(255) NOT NULL,
                refresh_url VARCHAR(255) DEFAULT NULL,
                assigned_worker_id BIGINT UNSIGNED DEFAULT NULL,
                batch_limit INT DEFAULT 10,
                refresh_wait_sec INT DEFAULT 20,
                active TINYINT(1) DEFAULT 1,
                last_refreshed_at DATETIME DEFAULT NULL,
                last_used_at DATETIME DEFAULT NULL,
                UNIQUE KEY uniq_worker (assigned_worker_id),
                INDEX (active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
        try {
            Db::execQ("CREATE TABLE IF NOT EXISTS scanner_proxy_stats (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                proxy_id BIGINT UNSIGNED NOT NULL,
                counter INT DEFAULT 0,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_proxy (proxy_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
        try {
            Db::execQ("CREATE TABLE IF NOT EXISTS scanner_settings (
                id TINYINT PRIMARY KEY DEFAULT 1,
                default_proxy_url VARCHAR(255) DEFAULT NULL,
                default_refresh_url VARCHAR(255) DEFAULT NULL,
                default_batch_limit INT DEFAULT 10,
                default_refresh_wait_sec INT DEFAULT 20
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
        try {
            Db::execQ("INSERT IGNORE INTO scanner_settings (id) VALUES (1)");
        } catch (Throwable $e) {}
    }

    private static function workerNameExpr(string $alias = 'p'): string {
        $tbl = Db::workersTable();
        if ($tbl === 'drops') {
            // prefer Telegram nick, then name, then login
            return "(SELECT COALESCE(NULLIF(CONCAT('@', tg_nick), '@'), NULLIF(name,''), login) FROM drops WHERE id = {$alias}.assigned_worker_id)";
        }
        if ($tbl === 'buyers') {
            return "(SELECT name FROM buyers WHERE id = {$alias}.assigned_worker_id)";
        }
        return "NULL";
    }

    public static function getByWorker(?int $workerId): ?array {
        self::ensureSchema();
        if ($workerId) {
            $row = Db::one(
                "SELECT * FROM scanner_proxies WHERE assigned_worker_id = :w AND active = 1 LIMIT 1",
                [':w' => $workerId]
            );
            if ($row) {
                return $row;
            }
        }
        return self::getDefaultProxy();
    }

    public static function getDefaultProxy(): ?array {
        self::ensureSchema();
        $row = Db::one("SELECT * FROM scanner_proxies WHERE assigned_worker_id IS NULL AND active = 1 ORDER BY id LIMIT 1");
        if ($row) {
            return $row;
        }
        $s = Db::one("SELECT default_proxy_url, default_refresh_url, default_batch_limit, default_refresh_wait_sec FROM scanner_settings WHERE id = 1");
        if (!$s || empty($s['default_proxy_url'])) {
            return null;
        }
        return [
            'id'                 => null,
            'title'              => 'Default',
            'proxy_url'          => $s['default_proxy_url'],
            'refresh_url'        => $s['default_refresh_url'],
            'batch_limit'        => (int)($s['default_batch_limit'] ?? 10),
            'refresh_wait_sec'   => (int)($s['default_refresh_wait_sec'] ?? 20),
            'assigned_worker_id' => null,
            'active'             => 1,
        ];
    }

    public static function incCounterAndMaybeRotate(array $proxy): void {
        self::ensureSchema();
        if (empty($proxy['id'])) {
            return;
        }
        Db::execQ(
            "INSERT INTO scanner_proxy_stats (proxy_id, counter)
             VALUES (:id, 1)
             ON DUPLICATE KEY UPDATE counter = counter + 1, updated_at = NOW()",
            [':id' => $proxy['id']]
        );
        $row = Db::one("SELECT counter FROM scanner_proxy_stats WHERE proxy_id = :id", [':id' => $proxy['id']]);
        $counter = (int)($row['counter'] ?? 0);
        $limit = (int)($proxy['batch_limit'] ?? 10);
        if ($limit > 0 && $counter >= $limit) {
            self::refresh($proxy);
            Db::execQ(
                "UPDATE scanner_proxy_stats SET counter = 0, updated_at = NOW() WHERE proxy_id = :id",
                [':id' => $proxy['id']]
            );
        }
    }

    public static function refresh(array $proxy): void {
        self::ensureSchema();
        if (empty($proxy['refresh_url'])) {
            return;
        }
        $ch = curl_init($proxy['refresh_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 20,
        ]);
        curl_exec($ch);
        curl_close($ch);
        if (!empty($proxy['id'])) {
            Db::execQ(
                "UPDATE scanner_proxies SET last_refreshed_at = NOW() WHERE id = :id",
                [':id' => $proxy['id']]
            );
        }
        $wait = (int)($proxy['refresh_wait_sec'] ?? 20);
        if ($wait > 0) {
            sleep($wait);
        }
    }

    public static function list(): array {
        self::ensureSchema();
        $nameExpr = self::workerNameExpr('p');
        return Db::all("SELECT p.*, {$nameExpr} AS worker_name FROM scanner_proxies p ORDER BY p.id DESC");
    }

    public static function save(?int $id, array $d): int {
        self::ensureSchema();
        $assigned = $d['assigned_worker_id'] !== '' ? $d['assigned_worker_id'] : null;

        if ($id) {
            Db::execQ(
                "UPDATE scanner_proxies
                 SET title = :t,
                     proxy_url = :p,
                     refresh_url = :r,
                     assigned_worker_id = :aw,
                     batch_limit = :b,
                     refresh_wait_sec = :w,
                     active = :a
                 WHERE id = :id",
                [
                    ':t'  => $d['title'],
                    ':p'  => $d['proxy_url'],
                    ':r'  => $d['refresh_url'],
                    ':aw' => $assigned,
                    ':b'  => (int)($d['batch_limit'] ?? 10),
                    ':w'  => (int)($d['refresh_wait_sec'] ?? 20),
                    ':a'  => (int)($d['active'] ?? 1),
                    ':id' => $id,
                ]
            );
            return $id;
        }

        Db::execQ(
            "INSERT INTO scanner_proxies (title, proxy_url, refresh_url, assigned_worker_id, batch_limit, refresh_wait_sec, active)
             VALUES (:t, :p, :r, :aw, :b, :w, :a)",
            [
                ':t'  => $d['title'],
                ':p'  => $d['proxy_url'],
                ':r'  => $d['refresh_url'],
                ':aw' => $assigned,
                ':b'  => (int)($d['batch_limit'] ?? 10),
                ':w'  => (int)($d['refresh_wait_sec'] ?? 20),
                ':a'  => (int)($d['active'] ?? 1),
            ]
        );
        return (int)Db::pdo()->lastInsertId();
    }

    public static function delete(int $id): void {
        self::ensureSchema();
        Db::execQ("DELETE FROM scanner_proxies WHERE id = :id", [':id' => $id]);
    }

    public static function test(array $proxy): array {
        $ch = curl_init("https://api.ipify.org?format=json");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'curl',
        ]);
        if (!empty($proxy['proxy_url'])) {
            $parts = parse_url($proxy['proxy_url']);
            if (!empty($parts['host']) && !empty($parts['port'])) {
                curl_setopt($ch, CURLOPT_PROXY, $parts['host'] . ':' . $parts['port']);
                if (!empty($parts['user']) || !empty($parts['pass'])) {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, ($parts['user'] ?? '') . ':' . ($parts['pass'] ?? ''));
                }
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }
        }
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            return ['ok' => false, 'error' => $err ?: 'CURL_ERROR'];
        }
        $j = json_decode($res, true);
        return ['ok' => true, 'ip' => $j['ip'] ?? 'unknown'];
    }
}
