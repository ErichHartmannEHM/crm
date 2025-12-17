<?php
declare(strict_types=1);
namespace Timer;
use DateTimeImmutable;
class Jobs {
    public static function schedule(\PDO $pdo, int|string $chatId, string $text, \DateTimeInterface $when): int {
        $pdo->prepare("INSERT INTO scheduled_jobs (chat_id,message_text,scheduled_at,status) VALUES (:c,:t,:w,'pending')")->execute([':c'=>(string)$chatId,':t'=>$text,':w'=>$when->format('Y-m-d H:i:s')]); return (int)$pdo->lastInsertId();
    }
    public static function fetchDue(\PDO $pdo, DateTimeImmutable $now, int $limit): array {
        $stmt=$pdo->prepare("SELECT id,chat_id,message_text,scheduled_at,try_count FROM scheduled_jobs WHERE status='pending' AND scheduled_at<=:n ORDER BY scheduled_at ASC,id ASC LIMIT :l");
        $stmt->bindValue(':n',$now->format('Y-m-d H:i:s')); $stmt->bindValue(':l',$limit,\PDO::PARAM_INT); $stmt->execute(); return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    public static function markSent(\PDO $pdo, int $id, \DateTimeInterface $now): void {
        $pdo->prepare("UPDATE scheduled_jobs SET status='sent',sent_at=:s,last_error=NULL WHERE id=:id AND status='pending'")->execute([':s'=>$now->format('Y-m-d H:i:s'),':id'=>$id]);
    }
    public static function markFailed(\PDO $pdo, int $id, string $err, \DateTimeInterface $now, int $max, int $delay): void {
        $pdo->beginTransaction(); try {
            $r=$pdo->prepare("SELECT try_count FROM scheduled_jobs WHERE id=:id FOR UPDATE"); $r->execute([':id'=>$id]); $row=$r->fetch(\PDO::FETCH_ASSOC); if(!$row) throw new \RuntimeException('job not found');
            $tries=(int)$row['try_count']+1;
            if ($tries>=$max) $pdo->prepare("UPDATE scheduled_jobs SET status='failed',try_count=:tc,last_error=:e WHERE id=:id")->execute([':tc'=>$tries,':e'=>mb_substr($err,0,4000),':id'=>$id]);
            else { $new=(new DateTimeImmutable($now->format('Y-m-d H:i:s')))->modify('+'.$delay.' seconds'); $pdo->prepare("UPDATE scheduled_jobs SET try_count=:tc,last_error=:e,scheduled_at=:w WHERE id=:id")->execute([':tc'=>$tries,':e'=>mb_substr($err,0,4000),':w'=>$new->format('Y-m-d H:i:s'),':id'=>$id]); }
            $pdo->commit();
        } catch (\Throwable $e){ if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
