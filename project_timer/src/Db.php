<?php
declare(strict_types=1);
namespace Timer;
class Db { public static function pdo(): \PDO { if (function_exists('db')) return \db(); throw new \RuntimeException('db() not found'); } }
