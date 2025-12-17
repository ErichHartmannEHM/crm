<?php
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/drop_auth.php';

if (isset($_GET['logout'])) { drop_logout(); header('Location:/drop/login.php'); exit; }

$err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $login = trim((string)($_POST['login'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');
  try {
    $d = db_row("SELECT * FROM drops WHERE login=? AND is_active=1", [$login]);
    if ($d && password_verify($pass, $d['pass_hash'])) {
      drop_login((int)$d['id']);
      header('Location:/drop/index.php'); exit;
    } else {
      $err = 'Неверный логин или пароль';
    }
  } catch (Throwable $e) { $err = 'Ошибка'; }
}
?>
<!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Работник — вход</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="dark">
<div class="card" style="max-width:420px;margin:80px auto">
  <div class="card-body">
    <h2>Вход для работника</h2>
    <?php if ($err): ?><div class="flash-item" style="margin:8px 0;color:#ef4444"><?= h($err) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field(); ?>
      <label>Логин<input name="login" required></label>
      <label>Пароль<input type="password" name="password" required></label>
      <div><button class="btn btn-primary">Войти</button></div>
    </form>
  </div>
</div>
</body></html>
