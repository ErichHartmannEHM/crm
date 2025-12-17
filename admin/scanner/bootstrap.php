<?php
// admin/scanner/bootstrap.php
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/classes/Db.php';
Db::$pdo = db(); // use project's PDO
