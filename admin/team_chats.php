<?php
require_once __DIR__.'/../lib/auth.php';
$title='Привязки Telegram'; $active='teams';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/settings.php';
require_once __DIR__.'/../lib/team_telegram.php';
require_once __DIR__.'/../app/Telegram/Bot.php';

use App\Telegram\Bot;

auth_require(); auth_require_admin(); csrf_check();

$team_id = (int)($_GET['team_id'] ?? 0);
if ($team_id<=0) { http_response_code(400); exit('team_id required'); }

// POST actions
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (isset($_POST['bind'])) {
    $chat_id   = (int)($_POST['chat_id'] ?? 0);
    $chat_type = trim((string)($_POST['chat_type'] ?? 'group'));
    $title     = trim((string)($_POST['title'] ?? ''));
    if ($chat_id) { ttch_bind($team_id,$chat_id,$chat_type,$title ?: null); set_flash('ok','Чат привязан.'); }
  }

  if (isset($_POST['toggle'])) {
    $chat_id = (int)$_POST['chat_id'];
    $active  = (int)$_POST['active'] ? false : true; // инвертируем
    ttch_set_active($team_id,$chat_id,$active);
    set_flash('ok', $active?'Активирован':'Деактивирован');
  }

  if (isset($_POST['unbind'])) {
    $chat_id = (int)$_POST['chat_id'];
    ttch_unbind($team_id,$chat_id);
    set_flash('ok','Привязка удалена.');
  }

  if (isset($_POST['test_send'])) {
    $text = trim((string)$_POST['text']);
    $token = config('telegram.bot_token') ?? setting_get('telegram_bot_token', null);
    if ($token && $text!=='') {
      $bot = new Bot($token);
      foreach (ttch_list_by_team($team_id) as $c) {
        if ((int)$c['is_active']===1) { $bot->sendMessage($c['chat_id'],$text,null,'HTML'); }
      }
      set_flash('ok','Тестовое сообщение отправлено активным чатам.');
    } else {
      set_flash('err','Нет токена бота или пустой текст.');
    }
  }

  header('Location: /admin/team_chats.php?team_id='.$team_id); exit;
}

// view
$team = db_row("SELECT id,name FROM teams WHERE id=?", [$team_id]);
$rows = ttch_list_by_team($team_id);

require __DIR__.'/_layout.php';
?>
<?php include __DIR__.'/_flash.php'; ?>

<h2>Команда #<?= (int)$team['id'] ?> — <?= h($team['name'] ?? '') ?></h2>

<div class="card">
  <div class="card-body">
    <h3>Добавить привязку</h3>
    <form method="post" class="grid cols-4">
      <?= csrf_field(); ?>
      <input type="hidden" name="bind" value="1">
      <label>Chat ID
        <input name="chat_id" type="number" required placeholder="-1001234567890">
      </label>
      <label>Тип
        <select name="chat_type">
          <option value="private">private</option>
          <option value="group">group</option>
          <option value="supergroup" selected>supergroup</option>
          <option value="channel">channel</option>
        </select>
      </label>
      <label>Название (необязательно)
        <input name="title" placeholder="Название чата">
      </label>
      <div style="align-self:end">
        <button class="btn btn-primary">Привязать</button>
      </div>
    </form>
    <p class="muted">Подсказка: Chat ID супергруппы обычно начинается с <code>-100</code>. Узнать его можно через @RawDataBot или через логи вебхука.</p>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <h3>Привязанные чаты</h3>
    <?php if (!$rows): ?>
      <p class="muted">Ещё нет привязок.</p>
    <?php else: ?>
      <table class="table">
        <thead><tr>
          <th>ID</th><th>Chat ID</th><th>Тип</th><th>Название</th><th>Статус</th><th>Создан</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= h($r['chat_id']) ?></td>
            <td><?= h($r['chat_type']) ?></td>
            <td><?= h($r['title']) ?></td>
            <td>
              <?php if ((int)$r['is_active']===1): ?>
                <span class="badge badge-success">активен</span>
              <?php else: ?>
                <span class="badge">выкл</span>
              <?php endif; ?>
            </td>
            <td><?= h($r['created_at']) ?></td>
            <td class="text-right">
              <form method="post" style="display:inline">
                <?= csrf_field(); ?>
                <input type="hidden" name="toggle" value="1">
                <input type="hidden" name="chat_id" value="<?= (int)$r['chat_id'] ?>">
                <input type="hidden" name="active"  value="<?= (int)$r['is_active'] ?>">
                <button class="btn btn-small"><?= (int)$r['is_active']? 'Выключить' : 'Включить' ?></button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('Удалить привязку?');">
                <?= csrf_field(); ?>
                <input type="hidden" name="unbind" value="1">
                <input type="hidden" name="chat_id" value="<?= (int)$r['chat_id'] ?>">
                <button class="btn btn-small btn-danger">Удалить</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <h3>Тестовая рассылка по команде</h3>
    <form method="post" class="grid cols-2">
      <?= csrf_field(); ?>
      <input type="hidden" name="test_send" value="1">
      <label>Текст сообщения
        <textarea name="text" rows="2" required>✅ Тест по команде #<?= (int)$team_id ?></textarea>
      </label>
      <div style="align-self:end">
        <button class="btn">Отправить</button>
      </div>
    </form>
  </div>
</div>

<p><a class="btn" href="/admin/teams.php">← Назад к списку команд</a></p>

<?php include __DIR__.'/_layout_footer.php'; ?>
