<?php
declare(strict_types=1);
namespace Timer;
use DateInterval; use DateTimeImmutable; use DateTimeInterface; use Exception;
class Clock {
    public static function get(\PDO $pdo): array {
        $row = $pdo->query("SELECT id,current_time,last_real_ts,mode,speed FROM project_clock WHERE id=1")->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('project_clock row not found');
        return $row;
    }
    public static function setNowAndMode(\PDO $pdo, DateTimeInterface $dt, string $mode, float $speed=1.0): void {
        if (!in_array($mode,['manual','auto'],true)) throw new Exception('Invalid mode');
        $pdo->prepare("INSERT INTO project_clock(id,current_time,last_real_ts,mode,speed) VALUES (1,:ct,UNIX_TIMESTAMP(),:m,:s) ON DUPLICATE KEY UPDATE current_time=VALUES(current_time), last_real_ts=VALUES(last_real_ts), mode=VALUES(mode), speed=VALUES(speed)")->execute([':ct'=>$dt->format('Y-m-d H:i:s'),':m'=>$mode,':s'=>$speed]);
    }
    public static function tickAndGet(\PDO $pdo): DateTimeImmutable {
        $pdo->beginTransaction();
        try {
            $row = self::get($pdo);
            $mode=$row['mode']; $speed=(float)$row['speed']; $current=new DateTimeImmutable($row['current_time']);
            if ($mode==='auto') {
                $nowTs=(int)$pdo->query("SELECT UNIX_TIMESTAMP()")->fetchColumn();
                $lastTs=(int)$row['last_real_ts'];
                if ($nowTs>$lastTs) {
                    $delta=$nowTs-$lastTs; $add=(int)round($delta*$speed);
                    if ($add>0) { $current=$current->add(new DateInterval('PT'.$add.'S')); $pdo->prepare("UPDATE project_clock SET current_time=:ct,last_real_ts=:ts WHERE id=1")->execute([':ct'=>$current->format('Y-m-d H:i:s'),':ts'=>$nowTs]); }
                    else { $pdo->prepare("UPDATE project_clock SET last_real_ts=:ts WHERE id=1")->execute([':ts'=>$nowTs]); }
                }
            }
            $pdo->commit(); return $current;
        } catch (\Throwable $e){ if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
