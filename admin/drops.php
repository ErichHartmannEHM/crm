<?php
require_once __DIR__.'/../lib/auth.php';
$title='Аккаунты работников'; $active='drops';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/partners.php';

auth_require(); auth_require_admin(); csrf_check();

if (!function_exists('set_flash')) {
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
  function set_flash(string $type, string $message): void { $_SESSION['__flash'][] = ['type'=>$type,'message'=>$message]; }
}

/* schema */
function db_has_column(string $t,string $c): bool { try { return db_exec("SHOW COLUMNS FROM `{$t}` LIKE ?",[$c])->fetch()?true:false; } catch(Throwable $e){ return false; } }
function ensure_drops_schema(): void {
  try {
    db_exec("CREATE TABLE IF NOT EXISTS `drops`(
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `login` VARCHAR(64) NOT NULL UNIQUE,
      `pass_hash` VARCHAR(255) NOT NULL,
      `name` VARCHAR(128) NOT NULL,
      `tg_nick` VARCHAR(64) NULL,
      `is_active` TINYINT(1) NOT NULL DEFAULT 1,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {}
  try { if (!db_has_column('drops','tg_nick')) db_exec("ALTER TABLE drops ADD COLUMN tg_nick VARCHAR(64) NULL AFTER name"); } catch(Throwable $e){}
  try { if (!db_has_column('cards','drop_id'))  db_exec("ALTER TABLE `cards` ADD COLUMN `drop_id` INT NULL AFTER `buyer_id`"); } catch(Throwable $e){}
  try { if (!db_has_column('cards','drop_name'))db_exec("ALTER TABLE `cards` ADD COLUMN `drop_name` VARCHAR(128) NULL AFTER `drop_id`"); } catch(Throwable $e){}
  try {
    db_exec("CREATE TABLE IF NOT EXISTS `drop_telegram_chats`(
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `drop_id` INT UNSIGNED NOT NULL,
      `chat_id` BIGINT NOT NULL,
      `is_active` TINYINT(1) NOT NULL DEFAULT 1,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_drop_chat(`drop_id`,`chat_id`),
      KEY idx_chat(`chat_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch(Throwable $e){}
}
ensure_drops_schema();
ensure_partners_schema();

/* POST */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  // Inline update via AJAX
  if (isset($_POST['inline_update'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id    = (int)($_POST['drop_id'] ?? 0);
    $field = (string)($_POST['field'] ?? '');
    $value = (string)($_POST['value'] ?? '');
    $allowed = ['name','tg_nick','login'];
    if (!$id || !in_array($field, $allowed, true)) {
      echo json_encode(['ok'=>false,'error'=>'bad_input']); exit;
    }
    $value = trim($value);
    if ($field==='tg_nick') {
      $value = $value!=='' ? (strpos($value,'@')===0?$value:'@'.$value) : null;
    } else {
      if ($value==='') { echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
    }
    try {
      db_exec("UPDATE drops SET `$field`=? WHERE id=?", [$value, $id]);
      echo json_encode(['ok'=>true,'value'=>($value ?? '')]); exit;
    } catch (Throwable $e) {
      echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
    }
  }

  // Создание
  if (isset($_POST['create_drop'])) {
    $login = trim((string)($_POST['login'] ?? ''));
    $name  = trim((string)($_POST['name'] ?? ''));
    $nick  = trim((string)($_POST['tg_nick'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    if ($login==='' || $name==='' || $pass==='') {
      set_flash('error','Имя, логин и пароль обязательны'); header('Location:/admin/drops.php'); exit;
    }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    try {
      db_exec("INSERT INTO drops(login,pass_hash,name,tg_nick,is_active,created_at) VALUES(?,?,?,?,1,NOW())",
        [$login,$hash,$name, ($nick!=='' ? (strpos($nick,'@')===0?$nick:'@'.$nick) : null)]);
      set_flash('success','Работник создан. Пароль: '.$pass);
    } catch (Throwable $e) {
      set_flash('error','Ошибка: '.$e->getMessage());
    }
    header('Location:/admin/drops.php'); exit;
  }

  // Смена пароля
  if (isset($_POST['reset_pass'])) {
    $id   = (int)$_POST['drop_id'];
    $pass = (string)($_POST['new_password'] ?? '');
    if ($pass===''){ set_flash('error','Пароль не может быть пустым'); header('Location:/admin/drops.php'); exit; }
    try {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      db_exec("UPDATE drops SET pass_hash=? WHERE id=?",[$hash,$id]);
      set_flash('success','Пароль обновлён. Новый: '.$pass);
    } catch (Throwable $e) { set_flash('error','Ошибка: '.$e->getMessage()); }
    header('Location:/admin/drops.php'); exit;
  }

  // Обновить имя/ник
  if (isset($_POST['update_profile'])) {
    $id   = (int)$_POST['drop_id'];
    $name = trim((string)($_POST['name'] ?? ''));
    $nick = trim((string)($_POST['tg_nick'] ?? ''));
    try {
      $nick = $nick!=='' ? (strpos($nick,'@')===0?$nick:'@'.$nick) : null;
      db_exec("UPDATE drops SET name=?, tg_nick=? WHERE id=?",[$name,$nick,$id]);
      set_flash('success','Профиль обновлён');
    } catch (Throwable $e) { set_flash('error','Ошибка: '.$e->getMessage()); }
    header('Location:/admin/drops.php'); exit;
  }

  // Актив/деактив
  if (isset($_POST['toggle_active'])) {
    $id = (int)$_POST['drop_id']; $to = (int)$_POST['to'];
    try { db_exec("UPDATE drops SET is_active=? WHERE id=?",[$to,$id]); set_flash('success','Статус обновлён'); } catch(Throwable $e){ set_flash('error','Ошибка'); }
    header('Location:/admin/drops.php'); exit;
  }

  // Привязать чат

  // Сохранить партнёров
  if (isset($_POST['save_partners'])) {
    $drop_id = (int)($_POST['drop_id'] ?? 0);
    $ids = isset($_POST['partner_ids']) ? (array)$_POST['partner_ids'] : [];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    try {
      drop_partners_save($drop_id, $ids);
      set_flash('success','Партнёры сохранены');
    } catch (Throwable $e) {
      set_flash('error','Не удалось сохранить партнёров: '.$e->getMessage());
    }
    header('Location:/admin/drops.php'); exit;
  }

  if (isset($_POST['bind_chat'])) {
    $id = (int)$_POST['drop_id'];
    $chat_id = trim((string)($_POST['chat_id'] ?? ''));
    if ($chat_id===''){ set_flash('error','chat_id пуст'); header('Location:/admin/drops.php'); exit; }
    try {
      db_exec("INSERT INTO drop_telegram_chats(drop_id,chat_id,is_active,created_at)
               VALUES(?,?,1,NOW())
               ON DUPLICATE KEY UPDATE is_active=VALUES(is_active)", [$id,$chat_id]);
      set_flash('success','Чат привязан к работнику');
    } catch(Throwable $e){ set_flash('error','Ошибка привязки: '.$e->getMessage()); }
    header('Location:/admin/drops.php'); exit;
  }

  // Вкл/выкл привязку
  if (isset($_POST['toggle_chat'])) {
    $row_id = (int)$_POST['row_id']; $to = (int)$_POST['to'];
    try { db_exec("UPDATE drop_telegram_chats SET is_active=? WHERE id=?",[$to,$row_id]); set_flash('success','Привязка обновлена'); } catch(Throwable $e){ set_flash('error','Ошибка'); }
    header('Location:/admin/drops.php'); exit;
  }

  // Удалить привязку
  if (isset($_POST['delete_chat'])) {
    $row_id = (int)$_POST['row_id'];
    try { db_exec("DELETE FROM drop_telegram_chats WHERE id=?",[$row_id]); set_flash('success','Привязка удалена'); } catch(Throwable $e){ set_flash('error','Ошибка'); }
    header('Location:/admin/drops.php'); exit;
  }

  // Удалить работника
  if (isset($_POST['delete_drop'])) {
    $id = (int)($_POST['drop_id'] ?? 0);
    if ($id <= 0) { set_flash('error','Некорректный ID работника'); header('Location:/admin/drops.php'); exit; }
    try {
      $unlinked = 0; $del_chats = 0; $del_map = 0;
      try { $unlinked = db_exec("UPDATE cards SET drop_id=NULL, drop_name=NULL WHERE drop_id=?",[$id])->rowCount(); } catch(Throwable $e){}
      try { $del_chats = db_exec("DELETE FROM drop_telegram_chats WHERE drop_id=?",[$id])->rowCount(); } catch(Throwable $e){}
      try { $del_map   = db_exec("DELETE FROM drop_cards WHERE drop_id=?",[$id])->rowCount(); } catch(Throwable $e){}
      db_exec("DELETE FROM drops WHERE id=?",[$id]);
      set_flash('success','Работник удалён. Снято привязок к картам: '.$unlinked.'; удалено чатов: '.$del_chats.'; удалено связей drop_cards: '.$del_map.'.');
    } catch (Throwable $e) {
      set_flash('error','Не удалось удалить работника: '.$e->getMessage());
    }
    header('Location:/admin/drops.php'); exit;
  }
}

/* LIST */
require __DIR__.'/_layout.php';

$drops = db_exec("SELECT d.*, (SELECT COUNT(*) FROM cards c WHERE c.drop_id=d.id) cards_cnt FROM drops d ORDER BY d.id DESC")->fetchAll();
?>
<?php include __DIR__.'/_flash.php'; ?>

<style>
/* ---------- Базовые карточки/таблицы ---------- */
.card{ background:#0b1220; border:1px solid rgba(148,163,184,.2); border-radius:12px; }
.table{ width:100%; border-collapse:collapse; }
.table thead th{ text-align:left; border-bottom:1px solid rgba(148,163,184,.25); padding:10px; }
.table tbody td{ border-bottom:1px dashed rgba(148,163,184,.18); padding:10px; vertical-align:top; }
.table .num{ text-align:right; }
.td-mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New",monospace; }

/* ---------- Грид формы создания (адаптив) ---------- */
.create-drop .grid.cols-4 { display:grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap:12px; }
.create-drop label{ display:block; width:100%; min-width:0; }
.create-drop input{ width:100%; }
@media (max-width: 990px){ .create-drop .grid.cols-4 { grid-template-columns: repeat(2,minmax(0,1fr)); } }
@media (max-width: 680px){ .create-drop .grid.cols-4 { grid-template-columns: 1fr; } }

/* ---------- Формы в строках ---------- */
.form-row{ display:flex; gap:8px; flex-wrap:wrap; align-items:end; }
.form-row input{ max-width:100%; }

/* ---------- Партнёры: тумблеры ---------- */
.pgrid{ display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:8px; }
@media (max-width: 780px){ .pgrid{ grid-template-columns: 1fr; } }
.switch{ position:relative; display:flex; align-items:center; gap:10px; padding:8px; background:#0b1220; border:1px solid rgba(148,163,184,.2); border-radius:10px; }
.switch input{ position:absolute; opacity:0; inset:0; }
.switch .slider{ width:42px; height:24px; background:#1f2937; border-radius:999px; position:relative; transition:.2s; flex-shrink:0; }
.switch .slider::after{ content:''; position:absolute; left:3px; top:3px; width:18px; height:18px; background:#94a3b8; border-radius:50%; transition:.2s; }
.switch input:checked + .slider{ background:#2563eb; }
.switch input:checked + .slider::after{ transform:translateX(18px); background:white; }
.switch .title{ flex:1; font-size:14px; }
.switch .badge{ font-size:12px; opacity:.8; }

.btn-small{ height:32px; padding:4px 10px; }

/* ---------- Мобильность/доступность ---------- */
:root { --tap-size: 44px; }
@media (max-width:600px){
  button,.btn,input,select,textarea{ min-height: var(--tap-size); font-size:15px; }
}

/* ---------- DROPS: таблица -> карточки ---------- */
@media (max-width:900px){
  .drops-table { display:block; border:0; min-width:0 !important; }
  .drops-table thead { display:none; }
  .drops-table tbody { display:grid; gap:12px; }
  .drops-table tbody tr{
    display:grid; grid-template-columns:1fr;
    background:#0f172a; border:1px solid #222; border-radius:12px; padding:12px;
    box-shadow:0 6px 18px rgba(0,0,0,.25);
  }
  .drops-table tbody tr > td{
    display:grid; grid-template-columns: auto 1fr;
    gap:8px; padding:6px 0; border:0; vertical-align:top;
  }
  .drops-table tbody tr > td::before{
    content:''; color:#8b93a7; font-size:12px; line-height:1.2; padding-top:4px; white-space:nowrap;
  }
  .drops-table tbody tr > td:nth-child(1)::before{ content:"ID"; }
  .drops-table tbody tr > td:nth-child(2)::before{ content:"Имя"; }
  .drops-table tbody tr > td:nth-child(3)::before{ content:"Telegram"; }
  .drops-table tbody tr > td:nth-child(4)::before{ content:"Логин"; }
  .drops-table tbody tr > td:nth-child(5)::before{ content:"Активен"; }
  .drops-table tbody tr > td:nth-child(6)::before{ content:"Карт"; }
  .drops-table tbody tr > td:nth-child(7)::before{ content:"Действия"; }

  /* формы действий — на всю ширину */
  .drops-table .form-row input,
  .drops-table .form-row .btn{ width:100%; }
}

/* ---------- CHATS: таблица -> карточки ---------- */
@media (max-width:900px){
  .tt-table { display:block; border:0; min-width:0 !important; }
  .tt-table thead { display:none; }
  .tt-table tbody { display:grid; gap:12px; }
  .tt-table tbody tr{
    display:grid; grid-template-columns:1fr;
    background:#0f172a; border:1px solid #222; border-radius:12px;
    padding:12px; box-shadow:0 6px 18px rgba(0,0,0,.25);
  }
  .tt-table tbody tr > td{
    display:grid; grid-template-columns:auto 1fr;
    gap:8px; padding:6px 0; border:0; vertical-align:top;
  }
  .tt-table tbody tr > td::before{
    content:''; color:#8b93a7; font-size:12px; line-height:1.2; padding-top:4px; white-space:nowrap;
  }
  .tt-table tbody tr > td:nth-child(1)::before{ content:"ID"; }
  .tt-table tbody tr > td:nth-child(2)::before{ content:"chat_id"; }
  .tt-table tbody tr > td:nth-child(3)::before{ content:"Активен"; }
  .tt-table tbody tr > td:nth-child(4)::before{ content:"Создан"; }
  .tt-table tbody tr > td:nth-child(5)::before{ content:"Действия"; }

  .tt-table .form-row .btn,
  .tt-table .form-row input{ width:100%; }
}

/* ---------- Средние ширины — липкие заголовки ---------- */
@media (min-width:901px) and (max-width:1200px){
  .drops-table thead th, .tt-table thead th { position: sticky; top: 0; background:#0b1220; z-index:1; }
}
</style>

<div class="card create-drop" style="margin-bottom:14px">
  <form method="post" class="grid cols-4" autocomplete="off" aria-label="Создать нового работника">
    <?= csrf_field(); ?>
    <label>Имя (для отображения)
      <input name="name" required aria-label="Имя работника">
    </label>
    <label>Telegram @ник (опц.)
      <input name="tg_nick" placeholder="@nickname" aria-label="Telegram ник работника">
    </label>
    <label>Логин
      <input name="login" required autocomplete="off" spellcheck="false" aria-label="Логин работника">
    </label>
    <label>Пароль
      <input name="password" required autocomplete="off" aria-label="Пароль работника">
    </label>
    <div style="grid-column:1/-1">
      <button class="btn btn-primary" name="create_drop" value="1" type="submit" aria-label="Создать работника">Создать работника</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="table drops-table">
      <thead>
        <tr>
          <th class="num">ID</th>
          <th>Имя</th>
          <th>Telegram</th>
          <th>Логин</th>
          <th>Активен</th>
          <th class="num">Карт</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($drops as $d): ?>
        <tr data-drop="<?= (int)$d['id'] ?>">
          <td class="num"><?= (int)$d['id'] ?></td>
          <td class="drop-edit" data-field="name" title="Кликните для редактирования"><?= h($d['name']) ?></td>
          <td class="td-mono drop-edit" data-field="tg_nick" title="Кликните для редактирования"><?= h($d['tg_nick'] ?? '') ?></td>
          <td class="td-mono drop-edit" data-field="login" title="Кликните для редактирования"><?= h($d['login']) ?></td>
          <td><?= (int)$d['is_active'] ? 'да' : 'нет' ?></td>
          <td class="num"><?= (int)$d['cards_cnt'] ?></td>
          <td>
            <form method="post" class="form-row" style="gap:8px" autocomplete="off" aria-label="Действия по работнику #<?= (int)$d['id'] ?>">
              <?= csrf_field(); ?>
              <input type="hidden" name="drop_id" value="<?= (int)$d['id'] ?>">

              <input name="name" value="<?= h($d['name']) ?>" style="width:140px" aria-label="Имя">
              <input name="tg_nick" value="<?= h($d['tg_nick'] ?? '') ?>" placeholder="@nick" style="width:120px" aria-label="Telegram ник">
              <button class="btn" name="update_profile" value="1" type="submit" aria-label="Сохранить профиль">Сохранить</button>

              <input name="new_password" placeholder="Новый пароль" style="width:140px" aria-label="Новый пароль">
              <button class="btn" name="reset_pass" value="1" type="submit" aria-label="Сменить пароль">Сменить пароль</button>

              <?php if ((int)$d['is_active']): ?>
                <button class="btn btn-danger" name="toggle_active" value="1" onclick="this.form.to.value=0" type="submit" aria-label="Деактивировать работника">Деактивировать</button>
                <input type="hidden" name="to" value="0">
              <?php else: ?>
                <button class="btn" name="toggle_active" value="1" onclick="this.form.to.value=1" type="submit" aria-label="Активировать работника">Активировать</button>
                <input type="hidden" name="to" value="1">
              <?php endif; ?>
            </form>

            <form method="post" style="margin-top:8px" onsubmit="return confirm('Удалить работника «<?= h($d['name']) ?>» и снять все привязки? Это действие необратимо.')" aria-label="Удалить работника #<?= (int)$d['id'] ?>">
              <?= csrf_field(); ?>
              <input type="hidden" name="drop_id" value="<?= (int)$d['id'] ?>">
              <button class="btn btn-danger btn-small" name="delete_drop" value="1" type="submit">Удалить работника</button>

            <!-- Партнёры работника -->
            <details style="margin-top:8px">
              <summary>Партнёры работника</summary>
              <form method="post" class="form-row" style="gap:8px" aria-label="Партнёры работника #<?= (int)$d['id'] ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="drop_id" value="<?= (int)$d['id'] ?>">
                <?php
                  $all_p = partners_all(true);
                  $sel   = drop_partner_ids((int)$d['id']);
                  if (!$sel || count($sel)===0) {
                    $def = calc_db_all("SELECT id FROM partners WHERE name IN ('EHM','Parfumer') AND is_active=1 ORDER BY name ASC", []);
                    $sel = array_map(fn($r)=> (int)$r['id'], $def);
                  }
                ?>
                <div class="pgrid" style="width:100%">
                  <?php foreach ($all_p as $p): $pid=(int)$p['id']; $checked = in_array($pid, $sel, true) ? 'checked' : ''; ?>
                    <label class="switch">
                      <input type="checkbox" name="partner_ids[]" value="<?=$pid?>" <?=$checked?>>
                      <span class="slider"></span>
                      <span class="title"><?=h($p['name'])?></span>
                      <span class="badge"><?=number_format((float)$p['percent'], 2, ',', ' ')?>%</span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div style="width:100%; display:flex; justify-content:flex-end">
                  <button class="btn" name="save_partners" value="1" type="submit">Сохранить партнёров</button>
                </div>
              </form>
            </details>

            </form>

            <!-- Привязка чатов -->
            <details style="margin-top:8px">
              <summary>Telegram-чаты работника</summary>
              <form method="post" class="form-row" style="gap:8px; margin:8px 0" autocomplete="off" aria-label="Привязать чат к работнику #<?= (int)$d['id'] ?>">
                <?= csrf_field(); ?>
                <input type="hidden" name="drop_id" value="<?= (int)$d['id'] ?>">
                <input name="chat_id" placeholder="chat_id (например -1001234567890)" class="td-mono" style="width:260px" aria-label="chat_id">
                <button class="btn" name="bind_chat" value="1" type="submit" aria-label="Привязать чат">Привязать</button>
              </form>
              <?php
                $chats = db_all("SELECT * FROM drop_telegram_chats WHERE drop_id=? ORDER BY id DESC", [(int)$d['id']]);
                if ($chats):
              ?>
              <div class="table-wrap">
                <table class="table compact tt-table">
                  <thead><tr><th class="num">#</th><th>chat_id</th><th>Активен</th><th>Создан</th><th>Действия</th></tr></thead>
                  <tbody>
                  <?php foreach($chats as $c): ?>
                    <tr>
                      <td class="num"><?= (int)$c['id'] ?></td>
                      <td class="td-mono"><?= h($c['chat_id']) ?></td>
                      <td><?= (int)$c['is_active'] ? 'да' : 'нет' ?></td>
                      <td class="td-mono"><?= h($c['created_at']) ?></td>
                      <td>
                        <form method="post" class="form-row" style="gap:6px" aria-label="Действия с привязкой #<?= (int)$c['id'] ?>">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="row_id" value="<?= (int)$c['id'] ?>">
                          <?php if((int)$c['is_active']): ?>
                            <button class="btn" name="toggle_chat" value="1" onclick="this.form.to.value=0" type="submit" aria-label="Выключить чат">Выключить</button>
                            <input type="hidden" name="to" value="0">
                          <?php else: ?>
                            <button class="btn" name="toggle_chat" value="1" onclick="this.form.to.value=1" type="submit" aria-label="Включить чат">Включить</button>
                            <input type="hidden" name="to" value="1">
                          <?php endif; ?>
                          <button class="btn btn-danger" name="delete_chat" value="1" type="submit" aria-label="Удалить привязку">Удалить</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php else: ?>
                <div class="muted">Привязок нет</div>
              <?php endif; ?>
            </details>
          </td>
        </tr>
      <?php endforeach; if(empty($drops)): ?>
        <tr><td colspan="7" class="muted">Работников нет</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>


<style>
  .drop-edit { cursor: pointer; position: relative; }
  .drop-edit.editing { outline: 1px dashed #556; }
  .drop-edit.saving { opacity: .7; }
  .drop-edit.error { background: #3b1f1f; outline: 1px solid #b33; }
  .drop-edit .inline-input {
    width: 100%; border: 1px solid #334; border-radius: 6px;
    padding: 4px 6px; font: inherit; background: #0b1220; color: #e7ecff;
  }
  .form-row input[name="name"],
  .form-row input[name="tg_nick"],
  .form-row input[name="login"],
  .form-row button[name="update_profile"] { display: none !important; }
</style>
<script>
(function(){
  function getCsrf() {
    var el = document.querySelector('input[name="csrf"]');
    return el ? el.value : '';
  }
  function startEdit(td){
    if (!td || td.classList.contains('editing')) return;
    var tr = td.closest('tr'); if (!tr) return;
    var id = tr.getAttribute('data-drop'); if (!id) return;
    var field = td.getAttribute('data-field'); if (!field) return;
    var oldText = (td.textContent || '').trim();
    td.classList.add('editing');
    var input = document.createElement('input');
    input.type = 'text'; input.value = oldText; input.className = 'inline-input'; input.autocomplete = 'off';
    td.innerHTML = ''; td.appendChild(input); input.focus(); input.select();
    var finished = false;
    function finish(ok, val){
      if (finished) return; finished = true;
      td.classList.remove('editing'); td.classList.remove('saving');
      td.textContent = ok ? (val==null?'':val) : oldText;
      if (!ok) { td.classList.add('error'); setTimeout(function(){ td.classList.remove('error'); }, 1400); }
    }
    function save(){
      var newVal = (input.value || '').trim();
      if (newVal === oldText) { finish(true, oldText); return; }
      td.classList.add('saving');
      var fd = new FormData();
      fd.append('inline_update','1');
      fd.append('drop_id', id);
      fd.append('field', field);
      fd.append('value', newVal);
      fd.append('csrf', getCsrf());
      fetch('/admin/drops.php', { method: 'POST', body: fd, headers: { 'Accept':'application/json' } })
        .then(function(r){ var ct = (r.headers.get('content-type')||''); if (ct.indexOf('application/json')!==-1) { return r.json(); } return r.text().then(function(t){ return {ok:false,error:(t||'bad_response')}; }); })
        .then(function(j){ if (j && j.ok) finish(true, j.value); else { finish(false); if (j && j.error) { try { alert('Ошибка: '+j.error); } catch(e){} } } })
        .catch(function(){ finish(false); });
    }
    input.addEventListener('keydown', function(e){
      if (e.key === 'Enter') { e.preventDefault(); save(); }
      else if (e.key === 'Escape') { e.preventDefault(); finish(true, oldText); }
    });
    input.addEventListener('blur', save);
  }
  document.addEventListener('click', function(e){
    var td = e.target.closest('.drop-edit');
    if (td) startEdit(td);
  });
})();
</script>

<?php include __DIR__.'/_layout_footer.php'; ?>