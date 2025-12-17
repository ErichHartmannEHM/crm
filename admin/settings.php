<?php
require_once __DIR__ . '/../lib/partners.php';
require_once __DIR__.'/../lib/auth.php';
$title='Настройки'; $active='settings';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/settings.php'; // setting_get / setting_set
require_once __DIR__.'/../lib/telegram.php';
@require_once __DIR__.'/../lib/limit.php';
@require_once __DIR__.'/../lib/balance.php';
require_once __DIR__.'/../lib/partners.php';
calc_bootstrap_schema();


auth_require();
// ======= TIMER (virtual clock) =======
require_once __DIR__ . '/../project_timer/bootstrap.php';
timer_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['timer_set'])) {
  if (function_exists('csrf_check')) { csrf_check(); }
  try {
    $dt    = trim((string)($_POST['timer_dt'] ?? ''));
    $mode  = (string)($_POST['timer_mode'] ?? 'manual');
    $speed = (float)($_POST['timer_speed'] ?? 1.0);
    if ($dt==='') throw new RuntimeException('Укажите дату/время');
    $dt = str_replace('T',' ', $dt) . ':00';
    $pdo = db();
    Timer\Clock::setNowAndMode($pdo, new DateTimeImmutable($dt), ($mode==='auto'?'auto':'manual'), ($mode==='auto'?$speed:1.0));
    $_SESSION['flash'][] = ['ok','Проектное время обновлено: ' . htmlspecialchars($dt)];
    header("Location: ".$_SERVER['REQUEST_URI']); exit;
  } catch (Throwable $e) {
    $_SESSION['flash'][] = ['err','Ошибка установки проектного времени: ' . htmlspecialchars($e->getMessage())];
  }
}

auth_require_admin();
csrf_check();
// === Telegram bot info for partner binding ===
$__tg_me_err = null; $__tg_raw = null;
$__tg_username = '';
try {
  $me = telegram_api('getMe', [], $__tg_raw, $__tg_me_err);
  if ($me && !empty($me['ok'])) { $__tg_username = (string)($me['result']['username'] ?? ''); }
} catch (Throwable $e) { /* ignore */ }
$__tg_link_secret = (string)setting_get('tg_link_secret','');
if ($__tg_link_secret==='') { try { $__tg_link_secret = bin2hex(random_bytes(16)); setting_set('tg_link_secret',$__tg_link_secret); } catch (Throwable $e) {} }
function _partner_build_payload_local(int $partner_id, string $sec): string {
  return 'bindp_'.$partner_id.'_'.substr(hash_hmac('sha256',(string)$partner_id,$sec),0,16);
}


// гарантируем наличие таблицы настроек
if (function_exists('settings_bootstrap')) { settings_bootstrap(); }

// простая флеш-система
if (!function_exists('set_flash')) {
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
  function set_flash(string $type, string $message): void {
    $_SESSION['__flash'][] = ['type'=>$type,'message'=>$message];
  }
}

/* Фолбэк: db_has_column (если нет в helpers) */
if (!function_exists('db_has_column')) {
  function db_has_column(string $t, string $c): bool {
    // Prefer INFORMATION_SCHEMA (works in prepared statements).
    try {
      $row = db_row(
        "SELECT COUNT(*) AS cnt
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1",
        [$t, $c]
      );
      if ($row && isset($row['cnt'])) {
        return (int)$row['cnt'] > 0;
      }
    } catch (Throwable $e) { /* ignore and fallback */ }

    // Fallback to SHOW COLUMNS without parameters (some drivers don't allow params for SHOW).
    if (!preg_match('~^[A-Za-z0-9_]+$~', $t) || !preg_match('~^[A-Za-z0-9_]+$~', $c)) return false;
    try { return db_exec("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'")->fetch() ? true : false; }
    catch (Throwable $e) { return false; }
  }
}/* ==================
   POST ACTIONS
   ================== */

// пересчёт лимитов за текущий месяц
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['recalc_limits'])) {
  $affected = 0;
  if (function_exists('limit_recalc_for_current_month')) {
    $affected = (int)limit_recalc_for_current_month(null);
  } else {
    // простой фолбэк: обновим лимиты по всем картам
    $ids = db_exec("SELECT id FROM cards")->fetchAll(PDO::FETCH_COLUMN,0);
    foreach ($ids as $cid) {
      // локальная формула: cap − Σdebit + Σhold за текущий месяц
      $cap = (float)(db_row("SELECT limit_cap_uah FROM cards WHERE id=?",[$cid])['limit_cap_uah'] ?? 0);
      $start = date('Y-m-01 00:00:00');
      $row = db_row("SELECT COALESCE(SUM(CASE WHEN type='debit' THEN amount_uah ELSE 0 END),0) AS sdeb,
                            COALESCE(SUM(CASE WHEN type='hold'  THEN amount_uah ELSE 0 END),0) AS shold
                       FROM payments
                      WHERE card_id=? AND created_at>=? AND IFNULL(is_void,0)=0",[$cid,$start]);
      $remain = max(0.0, min($cap, round($cap - (float)$row['sdeb'] + (float)$row['shold'], 2)));
      if (db_has_column('cards','limit_remaining_uah')) {
        db_exec("UPDATE cards SET limit_remaining_uah=? WHERE id=?",[$remain,$cid]);
      }
      $affected++;
    }
  }
  set_flash('ok', 'Лимиты пересчитаны за текущий месяц. Обновлено карт: '.$affected);
  header('Location: /admin/settings.php'); exit;
}

// пересчёт балансов всех карт
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['recalc_all_balances'])) {
  $affected = 0;
  if (function_exists('balance_recalc_for_all')) {
    $affected = (int)balance_recalc_for_all();
  } else {
    // фолбэк: Σ topup − Σ debit по payments
    $ids = db_exec("SELECT id FROM cards")->fetchAll(PDO::FETCH_COLUMN,0);
    // определим колонку для записи
    $col = 'balance_uah';
    try {
      $cols = db_exec("SHOW COLUMNS FROM `cards`")->fetchAll();
      $have=[]; foreach($cols as $c){ $have[$c['Field']]=true; }
      if (!empty($have['balance'])) $col='balance';
      if (!empty($have['current_balance'])) $col='current_balance';
      if (!empty($have['bal_uah'])) $col='bal_uah';
      if (empty($have[$col])) { db_exec("ALTER TABLE `cards` ADD COLUMN `balance_uah` DECIMAL(14,2) NOT NULL DEFAULT 0"); $col='balance_uah'; }
    } catch (Throwable $e) {}

    foreach ($ids as $cid) {
      $row = db_row("SELECT COALESCE(SUM(CASE WHEN type='topup' THEN amount_uah
                                              WHEN type='debit' THEN -amount_uah
                                              ELSE 0 END),0) AS s
                       FROM payments
                      WHERE card_id=? AND IFNULL(is_void,0)=0",[$cid]);
      $sum = round((float)$row['s'],2);
      db_exec("UPDATE cards SET `$col`=? WHERE id=?",[$sum,$cid]);
      $affected++;
    }
  }
  set_flash('ok', 'Балансы пересчитаны. Обновлено карт: '.$affected);
  header('Location: /admin/settings.php'); exit;
}

// сохранение настроек (курс + admin chat id)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['manual_fx']) || isset($_POST['admin_chat_id']))) {
  if (isset($_POST['manual_fx'])) {
    $fx = (float)$_POST['manual_fx'];
    if ($fx > 0) {
      setting_set('manual_fx', round($fx, 4));
    }
  }
  if (isset($_POST['admin_chat_id'])) {
    $chat = trim((string)$_POST['admin_chat_id']);
    setting_set('admin_chat_id', $chat);
  }
  set_flash('success', 'Настройки сохранены.');
  header('Location: /admin/settings.php'); exit;
}


/* ===== Partners CRUD ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['calc_partner_action'])) {
  $act = (string)($_POST['calc_partner_action'] ?? '');
  // Normalize chat id field (accept both tg_chat_id and chat_id)
  $normalize_chat = function($arr) {
      $raw = isset($arr['tg_chat_id']) ? (string)$arr['tg_chat_id'] : ((isset($arr['chat_id']) ? (string)$arr['chat_id'] : ''));
      $raw = trim($raw);
      // allow negatives for supergroups (-100...)
      if ($raw === '') return null;
      return preg_replace('~[^0-9\-]~', '', $raw);
  };

  if ($act==='add') {
    $name = trim((string)($_POST['name'] ?? ''));
    $pct  = (float)($_POST['percent'] ?? 0);
    $is_active = isset($_POST['is_active']) ? true : false;
    $tg_chat = $normalize_chat($_POST);
    if ($name!=='') {
      if (array_key_exists('tg_chat_id', $_POST) || array_key_exists('chat_id', $_POST)) partner_create($name, $pct, $is_active, null, $tg_chat);
      else partner_create($name, $pct, $is_active);
    }
    set_flash('success','Партнёр добавлен');
    header('Location: /admin/settings.php#partners'); exit;
  }

  if ($act==='update') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $pct  = (float)($_POST['percent'] ?? 0);
    $is_active = isset($_POST['is_active']) ? true : false;
    $has_chat_field = array_key_exists('tg_chat_id', $_POST) || array_key_exists('chat_id', $_POST);
    $tg_chat = $normalize_chat($_POST);
    if ($id>0) {
      if ($has_chat_field) partner_update($id, $name, $pct, $is_active, null, $tg_chat);
      else partner_update($id, $name, $pct, $is_active);
    }
    set_flash('success','Сохранено');
    header('Location: /admin/settings.php#partners'); exit;
  }

  if ($act==='bind_chat') {
    $id = (int)($_POST['id'] ?? 0);
    $tg_chat = $normalize_chat($_POST);
    if ($id>0) {
      $ok = false;
      // 1) попробовать прямой UPDATE по наиболее вероятным колонкам
      try {
        if (db_has_column('partners','tg_chat_id')) { db_exec("UPDATE partners SET tg_chat_id=? WHERE id=?", [$tg_chat, $id]); $ok=true; }
        elseif (db_has_column('partners','chat_id')) { db_exec("UPDATE partners SET chat_id=? WHERE id=?", [$tg_chat, $id]); $ok=true; }
        elseif (db_has_column('partners','telegram_chat_id')) { db_exec("UPDATE partners SET telegram_chat_id=? WHERE id=?", [$tg_chat, $id]); $ok=true; }
        elseif (db_has_column('partners','tg_chat')) { db_exec("UPDATE partners SET tg_chat=? WHERE id=?", [$tg_chat, $id]); $ok=true; }
        elseif (db_has_column('partners','telegram_chat')) { db_exec("UPDATE partners SET telegram_chat=? WHERE id=?", [$tg_chat, $id]); $ok=true; }
      } catch (Throwable $e) { /* ignore */ }

      // 2) если не удалось — попробовать через partner_update(...) как в «Сохранить»
      if (!$ok) {
        try {
          $row = db_row("SELECT id, name, percent, is_active FROM partners WHERE id=?", [$id]);
          if ($row) {
            if (function_exists('partner_update')) {
              partner_update((int)$row['id'], (string)$row['name'], (float)($row['percent'] ?? 0), (bool)$row['is_active'], null, $tg_chat);
              $ok = true;
            }
          }
        } catch (Throwable $e) { /* ignore */ }
      }

      // 3) финальный фолбэк — старый метод
      if (!$ok) {
        try { partner_set_chat_id($id, $tg_chat); $ok=true; } catch (Throwable $e) { /* ignore */ }
      }

      
      // verify persisted value (robustness)
      try {
        $actual = null;
        if (db_has_column('partners','tg_chat_id')) {
          $val = db_col("SELECT tg_chat_id FROM partners WHERE id=?", [$id]);
          $actual = $val === false ? null : (string)$val;
        }
        if (!is_null($actual)) {
          $ok = $ok && (($tg_chat === null || $tg_chat === '') ? ($actual === '' || $actual === null) : ((string)$actual === (string)$tg_chat));
        }
      } catch (Throwable $e) { /* ignore verification */ }
      set_flash($ok ? 'success' : 'error', $ok ? 'ChatID сохранён' : 'Не удалось сохранить ChatID');
    }
    header('Location: /admin/settings.php#partners'); exit;
  }

  if ($act==='unbind_chat') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0) {
      $ok = false;
      try {
        if (db_has_column('partners','tg_chat_id'))         { db_exec("UPDATE partners SET tg_chat_id=NULL WHERE id=?", [$id]); $ok=true; }
        elseif (db_has_column('partners','chat_id'))         { db_exec("UPDATE partners SET chat_id=NULL WHERE id=?", [$id]); $ok=true; }
        elseif (db_has_column('partners','telegram_chat_id')){ db_exec("UPDATE partners SET telegram_chat_id=NULL WHERE id=?", [$id]); $ok=true; }
        elseif (db_has_column('partners','tg_chat'))         { db_exec("UPDATE partners SET tg_chat=NULL WHERE id=?", [$id]); $ok=true; }
        elseif (db_has_column('partners','telegram_chat'))   { db_exec("UPDATE partners SET telegram_chat=NULL WHERE id=?", [$id]); $ok=true; }
      } catch (Throwable $e) { /* ignore */ }

      if (!$ok) { try { partner_set_chat_id($id, null); $ok=true; } catch (Throwable $e) { /* ignore */ } }

      set_flash($ok ? 'success' : 'error', $ok ? 'Чат отвязан' : 'Не удалось отвязать чат');
    }
    header('Location: /admin/settings.php#partners'); exit;
  }

  if ($act==='delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0) partner_delete($id);
    set_flash('ok','Удалено');
    header('Location: /admin/settings.php#partners'); exit;
  }
}
/* ===== Team percents save ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_team_percents'])) {
  $pct = $_POST['pct'] ?? [];
  $partner_ids = $_POST['team_partner_id'] ?? [];
  $partner_pcts = $_POST['team_partner_percent'] ?? [];
  if (is_array($pct)) {
    foreach ($pct as $tid => $p) {
      $tid = (int)$tid;
      $val = (float)$p;
      team_percent_set($tid, $val);

      // Save team partner mapping (optional)
      $pid_raw = $partner_ids[$tid] ?? '';
      $ppc_raw = $partner_pcts[$tid] ?? '';
      $pid = is_array($pid_raw) ? (int)reset($pid_raw) : (int)$pid_raw;
      if ($pid <= 0) {
        team_partner_set($tid, null, null);
      } else {
        $ppc = (string)$ppc_raw === '' ? null : (float)$ppc_raw;
        team_partner_set($tid, $pid, $ppc);
      }
    }
  }
  set_flash('success','Проценты команд сохранены');
  header('Location: /admin/settings.php#team-percents'); exit;
}
/* ==================
   VIEW DATA
   ================== */
$fx  = (float)setting_get('manual_fx', 42.00);
$admin_chat_id = (string)setting_get('admin_chat_id', '');

require __DIR__.'/_layout.php';
?>

<?php
require_once __DIR__ . '/../lib/partners.php'; include __DIR__.'/_flash.php'; ?>

<!-- Локальные мобильные стили для этой страницы -->
<style>
  :root { --tap-size: 44px; }

  /* Базовые улучшения форм на мобиле */
  @media (max-width: 600px) {
    button, .btn, input, select, textarea { min-height: var(--tap-size); font-size: 15px; }
  }
  input[type="number"] { width: 100%; }

  /* Сетка в шапке настроек (курс/чат) — адаптация */
  @media (max-width: 1100px) {
    .grid.cols-3 { grid-template-columns: 1fr 1fr; gap: 12px; }
  }
  @media (max-width: 720px) {
    .grid.cols-3 { grid-template-columns: 1fr; }
  }

  /* Карточки с действиями — вертикальный стэк на мобиле */
  .settings-actions h3 { margin-top: 0; }
  .settings-actions p  { margin-bottom: 10px; }
  .settings-actions form .btn { min-width: 260px; }

  @media (max-width: 720px) {
    .settings-actions .btn { width: 100%; }
  }

  /* Небольшие визуальные улучшения */
  .muted code { background: #0f172a; padding: 2px 6px; border-radius: 6px; }
</style>

<div class="card">
  <form method="post" class="grid cols-3">
    <?= csrf_field(); ?>

    <label>Курс USD→UAH
      <input
        type="number"
        step="0.0001"
        min="0.0001"
        name="manual_fx"
        inputmode="decimal"
        placeholder="напр. 41.3500"
        value="<?= h($fx) ?>">
    </label>

    <label>Admin Telegram Chat ID
      <input
        name="admin_chat_id"
        placeholder="-100… или ID чата"
        value="<?= h($admin_chat_id) ?>"
        autocomplete="off"
        spellcheck="false">
      <small class="muted">Можно указать ID супергруппы (обычно начинается с <code>-100</code>) или личный чат.</small>
    </label>

    <div style="align-self:end">
      <button class="btn btn-primary" type="submit" aria-label="Сохранить настройки">Сохранить</button>
    </div>
  </form>
</div>

<div class="card settings-actions">
  <div class="card-body">
    <h3>Лимит по картам</h3>
    <p class="muted">Пересчитать остатки лимита за <b>текущий месяц</b> для всех карт: <code>cap − Σdebit + Σhold</code> (обрезка 0…cap).</p>
    <form method="post" onsubmit="return confirm('Пересчитать лимиты за текущий месяц для всех карт?');">
      <?= csrf_field(); ?>
      <button class="btn btn-warning" name="recalc_limits" value="1" aria-label="Пересчитать лимиты за текущий месяц">
        Пересчитать лимиты за месяц
      </button>
    </form>
  </div>
</div>

<div class="card settings-actions">
  <div class="card-body">
    <h3>Баланс по картам</h3>
    <p class="muted">Пересчитать <b>все</b> балансы: <code>Σ topup − Σ debit</code> (без учёта void и холдов).</p>
    <form method="post" onsubmit="return confirm('Пересчитать БАЛАНСЫ для всех карт?');">
      <?= csrf_field(); ?>
      <button class="btn btn-warning" name="recalc_all_balances" value="1" aria-label="Пересчитать все балансы">
        Пересчитать все балансы
      </button>
    </form>
  </div>
</div>


<!-- =========================================================
     Партнёры для калькулятора (ChatID-привязка как в drops.php)
     ========================================================= -->
<div class="card" id="partners" style="margin-top:18px;">
  <div class="card-body">
    <h3 style="margin-top:0;">Партнёры для калькулятора</h3>
    <p class="muted" style="margin-top:-6px;">Создайте партнёров и задайте их проценты. Привязка выполняется <b>по ChatID</b> (как на странице работников). По умолчанию добавлены <b>EHM</b> и <b>Parfumer</b> (по 25%).</p>

    <?php
    require_once __DIR__ . '/../lib/partners.php';
    $plist = partners_all(false);
    ?>
    <style>
      .compact-table table{ width:100%; border-collapse:collapse; }
      .compact-table th, .compact-table td{ padding:6px 8px; border-bottom:1px solid #1e2a3d; vertical-align:middle; }
      .compact-table input[type="text"], .compact-table input[type="number"]{ height:32px; padding:6px 8px; border-radius:8px; width:100%; }
      .compact-table .actions{ display:flex; gap:6px; justify-content:flex-end; flex-wrap:wrap; }
      .compact-table .btn{ padding:6px 10px; font-size:12px; }
    </style>

    <div class="compact-table">
      <table>
        <thead>
          <tr>
            <th style="text-align:left;">Название</th>
            <th class="muted" style="width:120px; text-align:right;">%</th>
            <th class="muted" style="width:100px;">Активен</th>
            <th class="muted" style="width:220px;">ChatID</th>
            <th style="width:300px;"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($plist as $p): $pid=(int)$p['id']; $curChat=(string)($p['tg_chat_id'] ?? ''); ?>
          <tr>
            <form method="post">
              <?= csrf_field(); ?>
              <input type="hidden" name="id" value="<?= $pid ?>">
              <td><input type="text"   name="name"    value="<?= h($p['name']) ?>" required></td>
              <td><input type="number" name="percent" value="<?= h((string)($p['percent'] ?? '0')) ?>" step="0.01" min="0" max="100" style="text-align:right;"></td>
              <td style="text-align:center"><input type="checkbox" name="is_active" <?= !empty($p['is_active'])?'checked':'' ?>></td>
              <td>
                <input type="text" name="chat_id" value="<?= h($curChat) ?>" placeholder="например, -100123456789"
                       inputmode="numeric" pattern="^-?[0-9]+$" autocomplete="off" spellcheck="false">
              </td>
              <td class="actions">
                <button class="btn btn-primary" name="calc_partner_action" value="update">Сохранить</button>
                <button class="btn" name="calc_partner_action" value="bind_chat">Привязать</button>
                <?php if ($curChat !== ''): ?>
                  <button class="btn btn-danger" name="calc_partner_action" value="unbind_chat" onclick="return confirm('Отвязать партнёра от ChatID?');">Отвязать</button>
                  <small class="muted">ID: <?= h($curChat) ?></small>
                <?php endif; ?>
                <button class="btn btn-danger" name="calc_partner_action" value="delete" onclick="return confirm('Удалить партнёра?');">Удалить</button>
              </td>
            </form>
          </tr>
        <?php endforeach; ?>

          <tr>
            <form method="post">
              <?= csrf_field(); ?>
              <td><input type="text" name="name" placeholder="Новый партнёр" required></td>
              <td><input type="number" name="percent" value="0.00" step="0.01" min="0" max="100" style="text-align:right;"></td>
              <td style="text-align:center"><input type="checkbox" name="is_active" checked></td>
              <td><input type="text" name="chat_id" placeholder="ChatID (опционально)" inputmode="numeric" pattern="^-?[0-9]+$" autocomplete="off" spellcheck="false"></td>
              <td class="actions"><button class="btn" name="calc_partner_action" value="add">Добавить</button></td>
            </form>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
<!-- =========================================================
     Проценты команд для калькулятора
     ========================================================= -->
<div class="card" id="team-percents" style="margin-top:18px;">
  <div class="card-body">
    <h3 style="margin-top:0;">Проценты команд для калькулятора</h3>
    <p class="muted" style="margin-top:-6px;">Названия берутся со страницы «Команды». Здесь укажите процент для расчёта в калькуляторе доходности.</p>

    <?php
require_once __DIR__ . '/../lib/partners.php'; $tlist = teams_all_for_calc(); $tmap = team_percent_map(); $plist = partners_all(true); $tpmap = team_partner_map_all(); ?>
    <form method="post">
      <?= csrf_field(); ?>
      <input type="hidden" name="save_team_percents" value="1">
      <div class="compact-table">
        <table>
          <thead>
            <tr><th style="text-align:left;">Команда</th><th class="num" style="width:120px; text-align:right;">%</th><th style="width:260px;">Партнёр команды</th><th class="num" style="width:120px; text-align:right;">% партнёра</th></tr>
          </thead>
          <tbody>
          <?php
foreach ($tlist as $t):
  $tid = (int)$t['id'];
  $pc  = isset($tmap[$tid]) ? (float)$tmap[$tid] : 0.0;
  $tp  = isset($tpmap[$tid]) ? $tpmap[$tid] : null;
  $sel = $tp && isset($tp['partner_id']) ? (int)$tp['partner_id'] : 0;
  $tp_pct = ($tp && array_key_exists('percent', $tp) && $tp['percent'] !== null) ? (float)$tp['percent'] : '';
  $ph = ($tp && $sel) ? (isset($tp['default_percent']) ? (float)$tp['default_percent'] : '') : '';
?>
            <tr>
              <td><?= h($t['name']) ?></td>
              <td><input type="number" name="pct[<?= $tid ?>]" step="0.01" min="0" max="100" style="text-align:right; width:100%;" value="<?= h((string)$pc) ?>"></td>
              <td>
                <select name="team_partner_id[<?= $tid ?>]" style="width:100%; height:32px; border-radius:8px;">
                  <option value="">— нет партнёра —</option>
                  <?php foreach ($plist as $p): $pid=(int)$p['id']; ?>
                    <option value="<?= $pid ?>" <?= $sel === $pid ? 'selected' : '' ?>><?= h($p['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" name="team_partner_percent[<?= $tid ?>]" step="0.01" min="0" max="100" placeholder="<?= $ph !== '' ? h((string)$ph) : '' ?>" style="text-align:right; width:100%;" value="<?= $tp_pct === '' ? '' : h((string)$tp_pct) ?>"></td>
            </tr>
<?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="text-align:right; margin-top:10px;">
        <button class="btn btn-primary">Сохранить проценты</button>
      </div>
    </form>
  </div>
</div>
<?php
require_once __DIR__ . '/../lib/partners.php'; ?>
<?php // --- TIMER UI (virtual clock) begin --- ?>
<hr style="margin:24px 0;">
<h2>Проектное время (виртуальные часы)</h2>
<?php
  try {
    $pdo = db();
    $now = Timer\Clock::tickAndGet($pdo);
    $modeRow = $pdo->query("SELECT mode, speed FROM project_clock WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $curMode = $modeRow['mode'] ?? 'manual'; $curSpeed = (float)($modeRow['speed'] ?? 1.0);
  } catch (Throwable $e) {
    $now = new DateTimeImmutable('1970-01-01 00:00:00');
    $curMode = 'manual'; $curSpeed = 1.0;
  }
?>
<form method="post" action="">
  <?= function_exists('csrf_field') ? csrf_field() : '' ?>
  <input type="hidden" name="timer_set" value="1">
  <div class="card" style="padding:16px; border:1px solid #ddd; border-radius:8px; max-width:720px;">
    <div style="margin-bottom:8px;">Текущее проектное время: <strong><?=h($now->format('Y-m-d H:i:s'))?></strong></div>
    <label>Новое время (UTC):<br>
      <input type="datetime-local" name="timer_dt" value="<?=h($now->format('Y-m-d\\TH:i'))?>" required>
    </label>
    <div style="margin-top:8px;">
      <label>Режим:
        <select name="timer_mode">
          <option value="manual" <?= $curMode==='manual'?'selected':'' ?>>manual (вручную)</option>
          <option value="auto"   <?= $curMode==='auto'  ?'selected':'' ?>>auto (тикает само)</option>
        </select>
      </label>
      <label style="margin-left:12px;">Скорость (для auto):
        <input type="number" step="0.1" min="0.1" max="10" name="timer_speed" value="<?=h((string)$curSpeed)?>">
      </label>
    </div>
    <div style="margin-top:12px;">
      <button class="btn btn-primary" type="submit">Сохранить проектное время</button>
    </div>
    <p class="text-muted" style="margin-top:8px;">Сравнение задач идёт по проектному времени, а не по системным часам.</p>
  </div>
</form>
<?php // --- TIMER UI (virtual clock) end --- ?>

<?php
// --- Сканер заявок: настройки дефолтного мобильного прокси ---
try {
  db_exec("CREATE TABLE IF NOT EXISTS scanner_settings (
    id TINYINT PRIMARY KEY DEFAULT 1,
    default_proxy_url VARCHAR(255) DEFAULT NULL,
    default_refresh_url VARCHAR(255) DEFAULT NULL,
    default_batch_limit INT DEFAULT 10,
    default_refresh_wait_sec INT DEFAULT 20
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  db_exec("INSERT IGNORE INTO scanner_settings (id) VALUES (1)");
} catch(Throwable $e) {}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['scanner_save'])) {
  db_exec("INSERT INTO scanner_settings (id, default_proxy_url, default_refresh_url, default_batch_limit, default_refresh_wait_sec)
           VALUES (1, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE default_proxy_url=VALUES(default_proxy_url),
                                   default_refresh_url=VALUES(default_refresh_url),
                                   default_batch_limit=VALUES(default_batch_limit),
                                   default_refresh_wait_sec=VALUES(default_refresh_wait_sec)",
           [trim($_POST['default_proxy_url'] ?? ''),
            trim($_POST['default_refresh_url'] ?? ''),
            (int)($_POST['default_batch_limit'] ?? 10),
            (int)($_POST['default_refresh_wait_sec'] ?? 20)]);
  $_SESSION['flash'][] = ['ok', 'Сканер: настройки сохранены.'];
}
$S = db_row("SELECT * FROM scanner_settings WHERE id=1") ?? [];
?>
<section class="panel">
  <h2>Сканер заявок — мобильный прокси по умолчанию</h2>
  <form method="post" class="form-compact" autocomplete="off">
    <?= csrf_field(); ?>
    <div style="display:flex; gap:12px; flex-wrap:wrap;">
      <label style="flex:1 1 320px;">Прокси (http://user:pass@host:port)<br>
        <input type="text" name="default_proxy_url" value="<?=h($S['default_proxy_url'] ?? '')?>">
      </label>
      <label style="flex:1 1 320px;">Refresh URL<br>
        <input type="text" name="default_refresh_url" value="<?=h($S['default_refresh_url'] ?? '')?>">
      </label>
      <label>Лимит на IP<br><input type="number" name="default_batch_limit" min="1" value="<?= (int)($S['default_batch_limit'] ?? 10) ?>"></label>
      <label>Ожидание после refresh, сек<br><input type="number" name="default_refresh_wait_sec" min="0" value="<?= (int)($S['default_refresh_wait_sec'] ?? 20) ?>"></label>
    </div>
    <div style="margin-top:8px;"><button class="btn btn-primary" name="scanner_save" value="1">Сохранить</button>
      <?php if (!empty($S['default_refresh_url'])): ?>
        <a class="btn" target="_blank" href="<?=h($S['default_refresh_url'])?>">Рефреш IP (вручную)</a>
      <?php endif; ?>
      <a class="btn" href="/admin/scanner/index.php">Открыть «Сканер заявок»</a>
    </div>
  </form>
</section>

<?php include __DIR__.'/_layout_footer.php';  ?>
