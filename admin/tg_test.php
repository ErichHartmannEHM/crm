<?php
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/telegram.php';
require_once __DIR__.'/../lib/helpers.php';

$title='Тест Telegram'; $active='settings';
auth_require(); auth_require_admin(); csrf_check();

$msg = null; $migrate_note = null;
if($_SERVER['REQUEST_METHOD']==='POST'){
  $d = telegram_diag_full();
  if ($d['send_ok']) {
    $msg = "✅ Отправлено. Token={$d['token_masked']} chat={$d['admin_chat']}";
  } else {
    $msg = "❌ Ошибка: ".$d['send_err']." | Token={$d['token_masked']} chat={$d['admin_chat']}";
    if (!empty($d['migrate_to_chat_id'])) {
      $migrate = (string)$d['migrate_to_chat_id'];
      $migrate_note = "Чат был апгрейжен в супергруппу. Новый chat_id: <code>{$migrate}</code>. Сообщение уже будет отправлено по новому ID, а ID сохранён в настройках.";
    }
  }
}
require __DIR__.'/_layout.php';
?>
<div class="card"><div class="card-body">
  <form method="post">
    <?= csrf_field(); ?>
    <button class="btn btn-primary">Проверить отправку</button>
  </form>
  <?php if($msg): ?><div style="margin-top:10px"><?= $msg ?></div><?php endif; ?>
  <?php if($migrate_note): ?><div class="muted" style="margin-top:8px"><?= $migrate_note ?></div><?php endif; ?>
  <p class="muted" style="margin-top:12px">
    Если бот добавлен в чат и есть права на отправку, но сообщений нет — проверьте, что чат‑ID актуален (для супергрупп начинается на <code>-100</code>).
  </p>
</div></div>
<?php include __DIR__.'/_layout_footer.php'; ?>
