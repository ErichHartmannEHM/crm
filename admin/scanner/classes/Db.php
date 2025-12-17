<?php
// admin/scanner/classes/Db.php
class Db {
    /** @var ?PDO */
    public static $pdo = null;

    /**
     * Returns PDO instance injected from the main app (see bootstrap.php).
     *
     * @throws RuntimeException
     */
    public static function pdo(): PDO {
        if (!self::$pdo) {
            throw new RuntimeException('Scanner DB not initialized');
        }
        return self::$pdo;
    }

    public static function all(string $sql, array $params = []): array {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function one(string $sql, array $params = []): ?array {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    public static function execQ(string $sql, array $params = []): int {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function tableExists(string $table): bool {
        try {
            $row = self::one(
                "SELECT COUNT(*) AS c
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :t",
                [':t' => $table]
            );
            return (int)($row['c'] ?? 0) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Returns name of workers table used in this project (drops / buyers) or null.
     */
    public static function workersTable(): ?string {
        if (self::tableExists('drops')) {
            return 'drops';
        }
        if (self::tableExists('buyers')) {
            return 'buyers';
        }
        return null;
    }
}
