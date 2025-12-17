<?php
// /admin/team_balance.php
declare(strict_types=1);

require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/team_balance.php';
require_once __DIR__.'/../lib/log.php';
@require_once __DIR__.'/../lib/fx.php';

auth_require(); auth_require_admin(); csrf_check();

/* ==== INPUT ==== */
$team_id = (int)($_GET['id'] ?? 0);
$team = db_row("SELECT * FROM teams WHERE id=?", [$team_id]);
if (!$team) { http_response_code(404); echo "Team not found"; exit; }

/* ==== PERIOD (важно: не перетирать from/to на rollover) ==== */
$period = (string)($_POST['period'] ?? ($_GET['period'] ?? 'current'));
$from   = trim((string)($_POST['from'] ?? ''));
$to     = trim((string)($_POST['to'] ?? ''));

$rollAction = isset($_POST['rollover_apply']) || isset($_POST['rollover_cancel']);

if (!$rollAction) {
  if ($period === 'current') {
    [$from,$to] = month_bounds(date('Y-m'));
  } elseif ($period === 'prev') {
    [$from,$to] = month_bounds(date('Y-m', strtotime('-1 month')));
  } elseif ($period === 'custom') {
    if ($from==='') $from = date('Y-m-01 00:00:00');
    if ($to==='')   $to   = date('Y-m-d 23:59:59');
  }
} else {
  // rollover_* пришёл с hidden from/to — оставляем как есть
  if ($from==='') [$from,$to] = month_bounds(date('Y-m')); // техника безопасности
}

/* ==== Сохранение выбора HOLD в сессии (чтобы «держался») ==== */
if (!isset($_SESSION)) session_start();
if (array_key_exists('include_hold', $_POST)) {
  $_SESSION['__team_balance_hold'][$team_id] = (int)$_POST['include_hold'];
}
$include_hold = array_key_exists('include_hold', $_POST)
  ? ((int)$_POST['include_hold'] === 1)
  : ((int)($_SESSION['__team_balance_hold'][$team_id] ?? 1) === 1);

/* ==== ACTIONS ==== */
if (isset($_POST['add_topup'])) {
  $amt  = (float)str_replace(',','.', preg_replace('~[^\d\.,]~','', (string)($_POST['amount_usd'] ?? '0')));
  $note = trim((string)($_POST['note'] ?? ''));
  $dt   = trim((string)($_POST['created_at'] ?? '')); // datetime-local
  if ($amt > 0) {
    $topupId = team_topups_add($team_id, $amt, $note, (int)($_SESSION['user']['id'] ?? 0), $dt);
    log_op('team_topup','create', $topupId, ['team_id'=>$team_id,'amount_usd'=>$amt,'note'=>$note,'created_at'=>$dt ?: 'NOW()']);
  }
  header("Location: /admin/team_balance.php?id={$team_id}&period={$period}");
  exit;
}

if (isset($_POST['del_topup'])) {
  $tid = (int)($_POST['topup_id'] ?? 0);
  if ($tid > 0) {
    team_topups_delete($team_id, $tid);
    log_op('team_topup','delete', $tid, ['team_id'=>$team_id]);
  }
  header("Location: /admin/team_balance.php?id={$team_id}&period={$period}");
  exit;
}

/* Перенести остаток в следующий месяц (используем ПЕРЕДАННЫЕ from/to) */
if (isset($_POST['rollover_apply'])) {
  $R = team_balance_calc($team_id, $from, $to, $include_hold);     // <-- остаток за выбранный месяц
  $src_ym = ym_from_date($from);
  if (!team_rollover_exists($team_id, $src_ym) && (float)$R['remain_usd'] != 0.0) {
    $res = team_rollover_apply($team_id, $from, $to, (float)$R['remain_usd'], (int)($_SESSION['user']['id'] ?? 0));
    log_op('team_rollover','apply', $res['rollover_id'] ?? 0, ['team_id'=>$team_id,'src_ym'=>$src_ym,'amount_usd'=>$R['remain_usd']]);
  }
  // после переноса логично открыть следующий месяц (current)
  header("Location: /admin/team_balance.php?id={$team_id}&period=current");
  exit;
}

/* Отменить перенос */
if (isset($_POST['rollover_cancel'])) {
  $src_ym = (int)($_POST['src_ym'] ?? 0);
  if ($src_ym>0) {
    team_rollover_cancel($team_id, $src_ym);
    log_op('team_rollover','cancel', 0, ['team_id'=>$team_id,'src_ym'=>$src_ym]);
  }
  header("Location: /admin/team_balance.php?id={$team_id}&period={$period}");
  exit;
}

/* ==== CALC ==== */
$R          = team_balance_calc($team_id, $from, $to, $include_hold);
$topups     = team_topups_last($team_id, 20);
$fx_rate    = $R['fx'];
$src_ym     = ym_from_date($from);
$rollExist  = team_rollover_get($team_id, $src_ym);

/* ==== LAYOUT ==== */
$title = 'Баланс команды'; $active = 'teams';
require __DIR__.'/_layout.php';
?>
<style>
/* -------- Базовая карта/таблицы (как было) -------- */
.card { background:#0b1220; border:1px solid rgba(148,163,184,.2); border-radius:12px; margin-bottom:14px; }
.card .card-body { padding:14px 16px; }
.grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
@media (max-width: 980px){ .grid{ grid-template-columns:1fr; } }
.form-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.form-row > * { flex: 1 1 220px; }
.badge { display:inline-block; padding:3px 8px; border-radius:999px; border:1px solid #1f2937; background:#0f172a; font-size:12px; }
.num { text-align:right; }
.muted { color:#94a3b8; font-size:13px; }
.table { width:100%; border-collapse: collapse; }
.table thead th { text-align:left; border-bottom:1px solid rgba(148,163,184,.25); padding:8px; }
.table tbody td { border-bottom:1px dashed rgba(148,163,184,.18); padding:8px; }
.btn-ghost { background:transparent; border:1px solid rgba(148,163,184,.35); }
.inline { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
h1,h2,h3 { margin:0 0 8px 0; }
.actions { white-space:nowrap; text-align:right; }
.small { font-size:12px; }
.notice { background:#081426; border:1px solid rgba(56,189,248,.25); border-radius:10px; padding:10px 12px; }
.notice.ok { border-color: rgba(34,197,94,.35); }
.notice.warn { border-color: rgba(251,191,36,.35); }

/* -------- Мобильные улучшения/доступность -------- */
:root { --tap-size: 44px; }

@media (max-width: 600px){
  button, .btn, input, select, textarea { min-height: var(--tap-size); font-size: 15px; }
}

@media (max-width: 900px){
  .form-row > * { flex-basis: 100%; }            /* элементы формы в колонку */
  .inline { flex-direction: column; align-items: stretch; }
  .actions { text-align: left; white-space: normal; }
}

/* Переключатель периода + произвольный диапазон — аккуратно на мобиле */
#range > div { min-width: 220px; }

/* -------- Таблица «Последние пополнения» -> Плитки на мобиле -------- */
@media (max-width: 900px){
  .table-topups { display:block; border:0; min-width:0 !important; }
  .table-topups thead { display:none; }
  .table-topups tbody { display:grid; gap:12px; }
  .table-topups tbody tr{
    display:grid;
    grid-template-columns: 1fr;
    background: var(--panel, #0f172a);
    border:1px solid #222;
    border-radius:12px;
    padding:12px;
    box-shadow: 0 6px 18px rgba(0,0,0,.25);
  }
  .table-topups tbody tr > td{
    display:grid;
    grid-template-columns: auto 1fr;
    gap:8px;
    padding:6px 0;
    border:0;
    vertical-align:top;
  }
  .table-topups tbody tr > td::before{
    content:'';
    color:#8b93a7;
    font-size:12px;
    line-height:1.2;
    padding-top:4px;
    white-space:nowrap;
  }
  .table-topups tbody tr > td:nth-child(1)::before { content: "Дата/время"; }
  .table-topups tbody tr > td:nth-child(2)::before { content: "Сумма (USD)"; }
  .table-topups tbody tr > td:nth-child(3)::before { content: "Комментарий"; }
  .table-topups tbody tr > td:nth-child(4)::before { content: "Админ (ID)"; }
  .table-topups tbody tr > td:nth-child(5)::before { content: "Действие"; }

  .table-topups .actions form { width:100%; }
  .table-topups .actions .btn { width:100%; min-height: var(--tap-size); }
}

/* При средних ширинах — липкий заголовок */
@media (min-width: 901px) and (max-width: 1200px){
  .table-topups { display: table; }
  .table-topups thead th { position: sticky; top: 0; background:#0f172a; z-index:1; }
}
</style>

<div class="card">
  <div class="card-body">
    <div class="inline" style="justify-content:space-between;">
      <h1>Команда: <?= h($team['name']) ?> <span class="badge">ID <?= (int)$team_id ?></span></h1>
      <a class="btn btn-ghost" href="/admin/teams.php#team-<?= (int)$team_id ?>" aria-label="Вернуться к списку команд">← к списку команд</a>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <form method="post" class="form-row" autocomplete="off">
      <?= csrf_field(); ?>
      <div>
        <label>Период</label>
        <select name="period" class="form-control" onchange="const v=this.value;document.getElementById('range').style.display=(v==='custom'?'flex':'none');this.form.submit();">
          <option value="current" <?= $period==='current'?'selected':'' ?>>Текущий месяц</option>
          <option value="prev"    <?= $period==='prev'?'selected':'' ?>>Прошлый месяц</option>
          <option value="custom"  <?= $period==='custom'?'selected':'' ?>>Произвольный</option>
        </select>
      </div>
      <div id="range" class="inline" style="display: <?= $period==='custom'?'flex':'none' ?>;">
        <div>
          <label>От</label>
          <input type="datetime-local" name="from" value="<?= h(str_replace(' ','T',$from)) ?>" class="form-control">
        </div>
        <div>
          <label>До</label>
          <input type="datetime-local" name="to" value="<?= h(str_replace(' ','T',$to)) ?>" class="form-control">
        </div>
      </div>
      <div class="inline" style="flex:0 0 auto;">
        <input type="hidden" name="include_hold" value="0">
        <label class="inline" style="gap:6px; margin:0;">
          <input type="checkbox" name="include_hold" value="1" <?= $include_hold?'checked':'' ?> onchange="this.form.submit()">
          Учитывать HOLD
        </label>
        <button class="btn btn-primary" name="recalc" value="1" type="submit" aria-label="Пересчитать баланс команды за период">Пересчитать</button>
      </div>
    </form>
  </div>
</div>

<?php
  $isFullMonth = (date('Y-m-d H:i:s', strtotime($from)) === date('Y-m-01 00:00:00', strtotime($from)))
              && (date('Y-m-d H:i:s', strtotime($to))   === date('Y-m-01 00:00:00', strtotime('+1 month', strtotime($from))));
  if ($isFullMonth):
?>
<div class="card">
  <div class="card-body">
    <div class="inline" style="justify-content:space-between; align-items:center;">
      <div>
        <div class="muted small">Исходный месяц: <b><?= date('Y-m', strtotime($from)) ?></b> → в следующий месяц</div>
        <?php if ($rollExist): ?>
          <div class="notice ok">Перенос уже выполнен:
            <b><?= number_format((float)$rollExist['amount_usd'],2,'.',' ') ?> USD</b>
            (создан топап ID <?= (int)$rollExist['topup_id'] ?> на <?= h($to) ?>).
          </div>
        <?php else: ?>
          <div class="notice warn">Остаток к переносу:
            <b><?= number_format((float)$R['remain_usd'],2,'.',' ') ?> USD</b>
            (экв. <?= number_format((float)$R['remain_uah'],2,'.',' ') ?> UAH).
          </div>
        <?php endif; ?>
      </div>
      <div class="inline" style="gap:8px;">
        <?php if ($rollExist): ?>
          <form method="post" onsubmit="return confirm('Отменить перенос? Будет удалён созданный топап.');">
            <?= csrf_field(); ?>
            <input type="hidden" name="rollover_cancel" value="1">
            <input type="hidden" name="src_ym" value="<?= (int)$src_ym ?>">
            <input type="hidden" name="period" value="<?= h($period) ?>">
            <button class="btn btn-danger btn-ghost" type="submit" aria-label="Отменить перенос остатка">Отменить перенос</button>
          </form>
        <?php else: ?>
          <form method="post" onsubmit="return confirm('Перенести остаток <?= number_format((float)$R['remain_usd'],2,'.',' ') ?> USD на следующий месяц?');">
            <?= csrf_field(); ?>
            <input type="hidden" name="rollover_apply" value="1">
            <input type="hidden" name="include_hold" value="<?= $include_hold ? 1 : 0 ?>">
            <input type="hidden" name="from" value="<?= h($from) ?>">
            <input type="hidden" name="to" value="<?= h($to) ?>">
            <button class="btn btn-success" type="submit" <?= (float)$R['remain_usd']===0.0 ? 'disabled' : '' ?> aria-label="Перенести остаток на следующий месяц">Перенести остаток</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="grid">
  <div class="card">
    <div class="card-body">
      <h3>Итог за период</h3>
      <p class="muted">Курс USD→UAH берётся из <code>teams.manual_fx</code>, затем <code>settings.manual_fx</code>, затем из <code>fx_rates</code>; по умолчанию 40.00.</p>
      <table class="table">
        <tbody>
          <tr><td>Курс (USD→UAH)</td><td class="num"><?= number_format((float)$fx_rate,2,'.',' ') ?></td></tr>
          <tr><td>Пополнения (USD)</td><td class="num"><?= number_format((float)$R['topups_usd'],2,'.',' ') ?></td></tr>
          <tr><td>Списания (UAH)<?= $include_hold ? '' : ' (без HOLD)' ?></td><td class="num"><?= number_format((float)$R['spend_uah'],2,'.',' ') ?></td></tr>
          <tr><td>Списания (USD)</td><td class="num"><?= number_format((float)$R['spend_usd'],2,'.',' ') ?></td></tr>
        </tbody>
      </table>
      <h2 style="margin-top:8px;">Остаток: <b><?= number_format((float)$R['remain_usd'],2,'.',' ') ?> USD</b>
        <span class="muted">(~ <?= number_format((float)$R['remain_uah'],2,'.',' ') ?> UAH)</span></h2>
      <div class="muted small">Период: <?= h($R['from']) ?> — <?= h($R['to']) ?></div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h3>Добавить пополнение</h3>
      <form method="post" class="form-row" autocomplete="off">
        <?= csrf_field(); ?>
        <div>
          <label>Сумма (USD)</label>
          <input type="text" name="amount_usd" class="form-control" placeholder="Напр. 4500" inputmode="decimal" aria-label="Сумма пополнения в долларах США">
        </div>
        <div>
          <label>Комментарий</label>
          <input type="text" name="note" class="form-control" placeholder="Источник/пояснение (необязательно)" aria-label="Комментарий к пополнению">
        </div>
        <div>
          <label>Дата/время пополнения (опц.)</label>
          <input type="datetime-local" name="created_at" class="form-control" value="" aria-label="Дата и время пополнения">
          <div class="muted small">Оставьте пустым — будет текущая дата/время.</div>
        </div>
        <div style="flex:0 0 auto;">
          <button class="btn btn-success" name="add_topup" value="1" type="submit" aria-label="Сохранить пополнение">Сохранить</button>
        </div>
      </form>
      <p class="muted" style="margin-top:8px;">Указав дату, запишете пополнение «задним числом» — оно попадёт в расчёт того месяца, в чьи границы входит.</p>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <h3>Последние пополнения</h3>
    <?php if (!$topups): ?>
      <p class="muted">Пополнений пока нет.</p>
    <?php else: ?>
      <table class="table table-topups">
        <thead><tr>
          <th>Дата/время</th>
          <th class="num">Сумма (USD)</th>
          <th>Комментарий</th>
          <th class="num">Админ (ID)</th>
          <th class="actions"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($topups as $t): ?>
          <tr>
            <td><?= h($t['created_at']) ?></td>
            <td class="num"><?= number_format((float)$t['amount_usd'],2,'.',' ') ?></td>
            <td><?= h((string)$t['note']) ?></td>
            <td class="num"><?= (int)($t['created_by'] ?? 0) ?: '-' ?></td>
            <td class="actions">
              <form method="post" onsubmit="return confirm('Удалить пополнение на <?= number_format((float)$t['amount_usd'],2,'.',' ') ?> USD?');" style="display:inline">
                <?= csrf_field(); ?>
                <input type="hidden" name="del_topup" value="1">
                <input type="hidden" name="topup_id" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="period" value="<?= h($period) ?>">
                <button class="btn btn-danger btn-ghost" type="submit" aria-label="Удалить пополнение #<?= (int)$t['id'] ?>">Удалить</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__.'/_layout_footer.php'; ?>
