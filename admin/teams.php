<?php
require_once __DIR__.'/../lib/auth.php';
$title='Команды'; $active='teams';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/log.php';
@require_once __DIR__.'/../lib/fx.php';
@require_once __DIR__.'/../lib/team_balance.php'; // расчёты баланса + topups

auth_require(); auth_require_admin(); csrf_check();

/* ------------------------------------------------------------------
   ХЕЛПЕРЫ
   ------------------------------------------------------------------ */
if (!function_exists('column_exists')) {
  function column_exists(string $t, string $c): bool {
    try {
      return (int)db_cell(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name=? AND column_name=?",
        [$t,$c]
      ) > 0;
    } catch (Throwable $e) { return false; }
  }
}
if (!function_exists('table_exists')) {
  function table_exists(string $t): bool {
    try {
      return (int)db_cell(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name=?",
        [$t]
      ) > 0;
    } catch (Throwable $e) { return false; }
  }
}
function only_last4(string $s): string {
  $l = preg_replace('~\D~','', $s);
  $l = substr($l, -4);
  return $l !== '' ? $l : '????';
}
function card_last4_ui(int $card_id): string {
  try {
    if (column_exists('cards','last4')) {
      $v = db_cell("SELECT `last4` FROM cards WHERE id=?",[$card_id]);
      if ($v!==null && $v!=='') return only_last4((string)$v);
    }
    if (column_exists('cards','pan_last4')) {
      $v = db_cell("SELECT `pan_last4` FROM cards WHERE id=?",[$card_id]);
      if ($v!==null && $v!=='') return only_last4((string)$v);
    }
    if (column_exists('cards','pan')) {
      $v = db_cell("SELECT `pan` FROM cards WHERE id=?",[$card_id]);
      if ($v!==null && $v!=='') return only_last4((string)$v);
    }
  } catch (Throwable $e) { /* ignore */ }
  return only_last4((string)$card_id);
}
function cards_balance_col_ui_local(): ?string {
  foreach (['balance_uah','balance','current_balance','bal_uah'] as $cand) {
    if (column_exists('cards',$cand)) return $cand;
  }
  return null;
}
function team_cards_sum_uah(int $team_id): float {
  $col = cards_balance_col_ui_local(); if (!$col) return 0.0;
  $statusCond = "AND IFNULL(c.status,'waiting') NOT IN ('archived','processing','await_refund')";
  if (table_exists('buyers') && column_exists('cards','buyer_id')) {
    return (float)db_cell(
      "SELECT COALESCE(SUM(c.`$col`),0)
         FROM cards c
         JOIN buyers b ON b.id=c.buyer_id
        WHERE b.team_id=? $statusCond", [$team_id]
    );
  } elseif (column_exists('cards','team_id')) {
    return (float)db_cell(
      "SELECT COALESCE(SUM(c.`$col`),0) FROM cards c
        WHERE c.team_id=? $statusCond", [$team_id]
    );
  }
  return 0.0;
}
function dropops_active_cond(string $alias='o'): string {
  $cond = '';
  if (column_exists('drop_ops','is_void'))       $cond .= " AND IFNULL({$alias}.is_void,0)=0";
  if (column_exists('drop_ops','is_canceled'))   $cond .= " AND IFNULL({$alias}.is_canceled,0)=0";
  if (column_exists('drop_ops','is_cancelled'))  $cond .= " AND IFNULL({$alias}.is_cancelled,0)=0";
  if (column_exists('drop_ops','voided_at'))     $cond .= " AND {$alias}.voided_at IS NULL";
  if (column_exists('drop_ops','status'))        $cond .= " AND IFNULL({$alias}.status,'ok') NOT IN ('void','voided','canceled','cancelled','reversed')";
  return $cond;
}
function last_holds_for_team(int $team_id, int $limit=10): array {
  $hasPayments  = table_exists('payments');
  $hasVoid      = $hasPayments ? column_exists('payments','is_void') : false;
  $voidCond     = $hasVoid ? "AND IFNULL(p.is_void,0)=0" : "";
  if ($hasPayments) {
    if (table_exists('buyers') && column_exists('cards','buyer_id')) {
      return db_all(
        "SELECT p.created_at, p.amount_uah AS amt, p.card_id
           FROM payments p
           JOIN cards  c ON c.id=p.card_id
           JOIN buyers b ON b.id=c.buyer_id
          WHERE b.team_id=? AND p.type='hold' $voidCond
          ORDER BY p.created_at DESC LIMIT ".(int)$limit, [$team_id]
      );
    } elseif (column_exists('cards','team_id')) {
      return db_all(
        "SELECT p.created_at, p.amount_uah AS amt, p.card_id
           FROM payments p
           JOIN cards  c ON c.id=p.card_id
          WHERE c.team_id=? AND p.type='hold' $voidCond
          ORDER BY p.created_at DESC LIMIT ".(int)$limit, [$team_id]
      );
    }
    return [];
  }
  $cond = dropops_active_cond('o');
  if (table_exists('buyers') && column_exists('cards','buyer_id')) {
    return db_all(
      "SELECT o.created_at, o.amount_uah AS amt, o.card_id
         FROM drop_ops o
         JOIN cards  c ON c.id=o.card_id
         JOIN buyers b ON b.id=c.buyer_id
        WHERE b.team_id=? AND o.type='hold' $cond
        ORDER BY o.created_at DESC LIMIT ".(int)$limit, [$team_id]
    );
  } elseif (column_exists('cards','team_id')) {
    return db_all(
      "SELECT o.created_at, o.amount_uah AS amt, o.card_id
         FROM drop_ops o
         JOIN cards  c ON c.id=o.card_id
        WHERE c.team_id=? AND o.type='hold' $cond
        ORDER BY o.created_at DESC LIMIT ".(int)$limit, [$team_id]
    );
  }
  return [];
}
function last_ops_for_team(int $team_id, int $limit=20): array {
  $hasPayments  = table_exists('payments');
  $hasVoid      = $hasPayments ? column_exists('payments','is_void') : false;
  $voidCond     = $hasVoid ? "AND IFNULL(p.is_void,0)=0" : "";
  if ($hasPayments) {
    if (table_exists('buyers') && column_exists('cards','buyer_id')) {
      return db_all(
        "SELECT p.created_at, p.type, p.amount_uah AS amt, p.card_id
           FROM payments p
           JOIN cards  c ON c.id=p.card_id
           JOIN buyers b ON b.id=c.buyer_id
          WHERE b.team_id=? $voidCond
          ORDER BY p.created_at DESC LIMIT ".(int)$limit, [$team_id]
      );
    } elseif (column_exists('cards','team_id')) {
      return db_all(
        "SELECT p.created_at, p.type, p.amount_uah AS amt, p.card_id
           FROM payments p
           JOIN cards  c ON c.id=p.card_id
          WHERE c.team_id=? $voidCond
          ORDER BY p.created_at DESC LIMIT ".(int)$limit, [$team_id]
      );
    }
    return [];
  }
  $cond = dropops_active_cond('o');
  if (table_exists('buyers') && column_exists('cards','buyer_id')) {
    return db_all(
      "SELECT o.created_at, o.type, o.amount_uah AS amt, o.card_id
         FROM drop_ops o
         JOIN cards  c ON c.id=o.card_id
         JOIN buyers b ON b.id=c.buyer_id
        WHERE b.team_id=? $cond
        ORDER BY o.created_at DESC LIMIT ".(int)$limit, [$team_id]
    );
  } elseif (column_exists('cards','team_id')) {
    return db_all(
      "SELECT o.created_at, o.type, o.amount_uah AS amt, o.card_id
         FROM drop_ops o
         JOIN cards  c ON c.id=o.card_id
        WHERE c.team_id=? $cond
        ORDER BY o.created_at DESC LIMIT ".(int)$limit, [$team_id]
    );
  }
  return [];
}

/* Пагинация по пополнениям команды */
function team_topups_paged(int $team_id, int $limit, int $offset, ?int &$totalOut=null): array {
  ensure_team_topups_schema();
  try {
    $total = (int)db_cell("SELECT COUNT(*) FROM team_topups WHERE team_id=?", [$team_id]);
    $rows  = db_all(
      "SELECT * FROM team_topups WHERE team_id=? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
      [$team_id, $limit, $offset]
    );
  } catch (Throwable $e) {
    $total = 0; $rows = [];
  }
  if ($totalOut !== null) $totalOut = $total;
  return $rows;
}

/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (isset($_POST['create_team'])) {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name !== '') {
      try {
        if (column_exists('teams','balance_usd')) {
          db_exec("INSERT INTO teams(name,balance_usd,is_archived) VALUES(?,?,0)", [$name, 0]);
        } else {
          db_exec("INSERT INTO teams(name,is_archived) VALUES(?,0)", [$name]);
        }
        log_op('team','create', db()->lastInsertId(), ['name'=>$name]);
      } catch (Throwable $e) {}
    }
    header('Location: /admin/teams.php'); exit;
  }

  if (isset($_POST['update_team'])) {
    $id   = (int)$_POST['team_id'];
    $name = trim((string)($_POST['name'] ?? ''));
    if ($id>0) {
      try {
        db_exec("UPDATE teams SET name=? WHERE id=?", [$name,$id]);
        log_op('team','update', $id, ['name'=>$name]);
      } catch (Throwable $e) {}
    }
    header('Location: /admin/teams.php#team-'.$id); exit;
  }

  if (isset($_POST['archive_team'])) {
    $id = (int)$_POST['team_id'];
    try {
      db_exec("UPDATE teams SET is_archived=1 WHERE id=?", [$id]);
      log_op('team','archive', $id, []);
    } catch (Throwable $e) {}
    header('Location: /admin/teams.php'); exit;
  }

  if (isset($_POST['create_buyer'])) {
    $team_id = (int)$_POST['team_id'];
    $name    = trim((string)($_POST['name'] ?? ''));
    $chat_id = trim((string)($_POST['telegram_chat_id'] ?? ''));
    try {
      db_exec("INSERT INTO buyers(team_id,name,telegram_chat_id,is_archived) VALUES(?,?,?,0)", [$team_id,$name,$chat_id]);
      log_op('buyer','create', db()->lastInsertId(), ['team_id'=>$team_id,'name'=>$name,'telegram_chat_id'=>$chat_id]);
    } catch (Throwable $e) {}
    header('Location: /admin/teams.php#team-'.$team_id); exit;
  }

  if (isset($_POST['archive_buyer']) || (isset($_POST['action']) && $_POST['action']==='archive_buyer')) {
    $buyer_id = (int)($_POST['buyer_id'] ?? 0);
    $team_id  = (int)($_POST['team_id']  ?? 0);
    try {
      db_exec("UPDATE buyers SET is_archived=1 WHERE id=?", [$buyer_id]);
      log_op('buyer','archive', $buyer_id, []);
    } catch (Throwable $e) {}
    header('Location: /admin/teams.php'.($team_id? '#team-'.$team_id : '')); exit;
  }

  // Telegram-чаты
  if (isset($_POST['tt_bind'])) {
    $team_id   = (int)$_POST['team_id'];
    $chat_id   = (int)($_POST['tt_chat_id'] ?? 0);
    $chat_type = trim((string)($_POST['tt_chat_type'] ?? 'supergroup'));
    $title     = trim((string)($_POST['tt_title'] ?? ''));
    if ($team_id>0 && $chat_id !== 0) {
      try {
        db_exec("INSERT INTO team_telegram_chats(team_id,chat_id,chat_type,title,is_active)
                 VALUES(?,?,?,?,1)
                 ON DUPLICATE KEY UPDATE team_id=VALUES(team_id), chat_type=VALUES(chat_type),
                                         title=VALUES(title), is_active=1",
                [$team_id, $chat_id, $chat_type, ($title ?: null)]);
        log_op('team_chat','bind', $team_id, ['chat_id'=>$chat_id,'type'=>$chat_type,'title'=>$title]);
      } catch (Throwable $e) {}
    }
    header('Location: /admin/teams.php#team-'.$team_id); exit;
  }
  if (isset($_POST['tt_toggle'])) {
    $team_id = (int)$_POST['team_id'];
    $chat_id = (int)$_POST['chat_id'];
    $active  = (int)$_POST['active'];
    try {
      db_exec("UPDATE team_telegram_chats SET is_active=? WHERE team_id=? AND chat_id=?",
              [ $active ? 0 : 1, $team_id, $chat_id ]);
      log_op('team_chat','toggle', $team_id, ['chat_id'=>$chat_id,'active'=>!$active]);
    } catch (Throwable $e) {}
    header('Location: /admin/teams.php#team-'.$team_id); exit;
  }
  if (isset($_POST['tt_unbind'])) {
    $team_id = (int)$_POST['team_id'];
    $chat_id = (int)$_POST['chat_id'];
    try {
      db_exec("DELETE FROM team_telegram_chats WHERE team_id=? AND chat_id=?", [$team_id,$chat_id]);
      log_op('team_chat','unbind', $team_id, ['chat_id'=>$chat_id]);
    } catch (Throwable $e) {}
    header('Location: /admin/teams.php#team-'.$team_id); exit;
  }

  // Пополнения
  if (isset($_POST['team_topup_add'])) {
    ensure_team_topups_schema();
    $team_id = (int)$_POST['team_id'];
    $amount  = round((float)$_POST['amount_usd'],2);
    $note    = trim((string)($_POST['note'] ?? ''));
    $dt      = trim((string)($_POST['created_at'] ?? ''));
    if ($team_id>0 && $amount>0) {
      try {
        $topup_id = team_topups_add($team_id, $amount, ($note!==''?$note:null), null, $dt?:null);
        log_op('team_topup','add', $topup_id, ['team_id'=>$team_id,'amount_usd'=>$amount,'note'=>$note]);
      } catch (Throwable $e) {}
    }
    header('Location: /admin/teams.php#team-'.$team_id); exit;
  }
  if (isset($_POST['team_topup_delete'])) {
    ensure_team_topups_schema();
    $team_id  = (int)$_POST['team_id'];
    $topup_id = (int)$_POST['topup_id'];
    try {
      team_topups_delete($team_id, $topup_id);
      log_op('team_topup','delete', $topup_id, ['team_id'=>$team_id]);
    } catch (Throwable $e) {}
    header('Location: /admin/teams.php#team-'.$team_id); exit;
  }
}

/* ========================= DATA ========================= */
require __DIR__.'/_layout.php';
$teams = db_exec("SELECT * FROM teams WHERE IFNULL(is_archived,0)=0 ORDER BY id DESC")->fetchAll();
?>
<style>
/* --- Компактный summary + мобильная укладка --- */
.teams-wrap { display:flex; flex-direction:column; gap:12px; }
.team-accordion {
  border:1px solid rgba(148,163,184,.2);
  border-radius:12px;
  background:#0b1220;
  overflow:hidden;
}
.team-accordion summary{
  list-style:none; cursor:pointer;
  padding:12px 14px;
  display:grid; grid-template-columns: 1fr auto auto;
  align-items:center; gap:12px;
}
.team-accordion summary::-webkit-details-marker{ display:none; }
.team-title{ font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.summary-metrics{ display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
.summary-actions{ display:flex; gap:8px; align-items:center; justify-content:flex-end; }
.badge-soft{ padding:4px 8px; border-radius:999px; background:#0f172a; border:1px solid #1f2937; font-size:12px; }
.muted{ color:#94a3b8; font-size:12px; }

/* мобильная укладка summary */
@media (max-width: 780px){
  .team-accordion summary{ grid-template-columns:1fr; row-gap:6px; align-items:flex-start; padding:10px 12px; }
  .team-title{ white-space:normal; font-size:16px; }
  .summary-metrics,.summary-actions{ justify-content:flex-start; }
  .badge-soft{ font-size:11.5px; padding:4px 8px; }
}

.team-body { padding:14px 16px 16px; border-top:1px dashed rgba(148,163,184,.25); }
.team-grid { display:grid; grid-template-columns: 1.25fr 1fr; gap:16px; }
@media (max-width: 980px){ .team-grid{ grid-template-columns:1fr; } }

.form-compact .form-row, .form-compact label{ display:block; margin-bottom:10px; }
.form-compact input, .form-compact select, .form-compact textarea{ width:100%; }

.table-borders thead th{ border-bottom:1px solid rgba(148,163,184,.25); }
.table-borders tbody td{ border-bottom:1px dashed rgba(148,163,184,.18); }
.btn-ghost { background:transparent; border:1px solid rgba(148,163,184,.35); }
.btn-row{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.num{ text-align:right; }

/* метрики баланса */
.metrics{ display:grid; grid-template-columns: repeat(4,1fr); gap:8px; margin-bottom:10px; }
.metrics .card-mini{ background:#0f172a; border:1px solid #1f2937; border-radius:10px; padding:8px 10px; }
.metrics .card-mini .t{ font-size:12px; color:#94a3b8; }
.metrics .card-mini .v{ font-weight:600; }
@media (max-width: 900px){ .metrics{ grid-template-columns: repeat(2,1fr); } }

/* Пополнить — грид без inline-стилей: нормально переезжает на мобилу */
.topup-grid{ display:grid; grid-template-columns: 1fr 1fr 2fr auto; gap:8px; align-items:end; }
@media (max-width: 900px){
  .topup-grid{ grid-template-columns: 1fr; }
  .topup-grid .btn{ width:100%; }
  .topup-grid input, .topup-grid select, .topup-grid textarea{ font-size:16px; min-height:44px; }
}

/* форма добавления баера внутри команды */
.buyer-create-form{ display:grid; grid-template-columns: 1.3fr 1fr auto; gap:8px; align-items:end; margin: 0 0 8px 0; }
@media (max-width: 900px){
  .buyer-create-form{ grid-template-columns: 1fr; }
  .buyer-create-form .btn{ width:100%; }
}

/* таблицы -> карточки на мобиле */
@media (max-width: 900px){
  .tt-table,.buyers-table,.ops-table,.topups-table { display:block; border:0; min-width:0 !important; }
  .tt-table thead,.buyers-table thead,.ops-table thead,.topups-table thead { display:none; }
  .tt-table tbody,.buyers-table tbody,.ops-table tbody,.topups-table tbody { display:grid; gap:12px; }
  .tt-table tbody tr,.buyers-table tbody tr,.ops-table tbody tr,.topups-table tbody tr {
    display:grid; grid-template-columns:1fr;
    background:#0f172a; border:1px solid #222; border-radius:12px;
    padding:12px; box-shadow:0 6px 18px rgba(0,0,0,.25);
  }
  .tt-table tbody tr > td,
  .buyers-table tbody tr > td,
  .ops-table tbody tr > td,
  .topups-table tbody tr > td{ display:block; padding:6px 0; border:0; }

  /* карточка одного пополнения: показываем только первый TD (дата+сумма+заметка) и TD с кнопкой */
  .topups-table tbody tr > td:nth-child(2),
  .topups-table tbody tr > td:nth-child(3){ display:none; }
}

/* доступность */
:root { --tap-size: 44px; }
@media (max-width: 600px){
  button, .btn, input, select, textarea { min-height: var(--tap-size); font-size: 15px; }
}

/* Пагинация списков пополнений */
.pager{ display:flex; gap:8px; align-items:center; justify-content:flex-end; margin-top:8px; }
.pager a{ display:inline-block; padding:6px 10px; border:1px solid #2a3342; border-radius:8px; background:#0f172a; color:#e5e7eb; text-decoration:none; }
.pager a[aria-disabled="true"]{ opacity:.5; pointer-events:none; }
</style>

<div class="card" style="margin-bottom:14px">
  <form method="post" class="form-compact" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap" autocomplete="off">
    <?= csrf_field(); ?>
    <label style="flex:1 1 360px">Название команды
      <input name="name" placeholder="Название" required>
    </label>
    <button class="btn btn-primary" name="create_team" value="1" style="height:38px" type="submit" aria-label="Создать команду">Создать команду</button>
  </form>
</div>

<div class="teams-wrap">
<?php foreach($teams as $t):
  $teamId = (int)$t['id'];

  $calc = team_balance_calc($teamId, '', '', true);
  $fx   = (float)$calc['fx'];
  $remain_usd = (float)$calc['remain_usd'];
  $remain_uah = (float)$calc['remain_uah'];
  $topups_usd = (float)$calc['topups_usd'];
  $spend_usd  = (float)$calc['spend_usd'];
  $spend_uah  = (float)$calc['spend_uah'];

  $sum_uah = team_cards_sum_uah($teamId);
  $sum_usd = $fx>0 ? $sum_uah / $fx : 0.0;

  try { $teamChats = db_exec("SELECT * FROM team_telegram_chats WHERE team_id=? ORDER BY id DESC", [$teamId])->fetchAll(); }
  catch (Throwable $e) { $teamChats = []; }

  $buyers = db_exec("SELECT * FROM buyers WHERE team_id=? AND IFNULL(is_archived,0)=0 ORDER BY id DESC",[$teamId])->fetchAll();

  /* Пагинация пополнений: 5 на страницу: ?topups_team=<id>&topups_page=N */
  ensure_team_topups_schema();
  $perPage = 5;
  $reqTeam = (int)($_GET['topups_team'] ?? 0);
  $page    = ($reqTeam === $teamId) ? max(1, (int)($_GET['topups_page'] ?? 1)) : 1;
  $totalTopups = 0;
  $topups = team_topups_paged($teamId, $perPage, ($page-1)*$perPage, $totalTopups);
  $pagesTotal = max(1, (int)ceil($totalTopups / $perPage));

  $holds = last_holds_for_team($teamId, 6);
  $ops   = last_ops_for_team($teamId, 14);

  // ссылки пагинации
  $mkLink = function(int $p) use ($teamId) {
    return '/admin/teams.php?topups_team='.$teamId.'&topups_page='.$p.'#team-'.$teamId;
  };
?>
  <details class="team-accordion" id="team-<?= $teamId ?>">
    <summary class="summary-row">
      <div class="team-title"><?= h($t['name']) ?></div>
      <div class="summary-metrics">
        <span class="badge-soft">Реальный остаток: <b>$<?= number_format($remain_usd,2,'.',' ') ?></b> ≈ <?= money_uah($remain_uah) ?></span>
        <span class="badge-soft">По картам: <?= money_uah($sum_uah) ?> (≈ $<?= number_format($sum_usd,2,'.',' ') ?>)</span>
      </div>
      <div class="summary-actions">
        <form method="post" onsubmit="return confirm('Отправить команду «<?= h($t['name']) ?>» в архив?')" aria-label="Архивировать команду">
          <?= csrf_field(); ?>
          <input type="hidden" name="team_id" value="<?= $teamId ?>">
          <button class="btn btn-danger btn-ghost" name="archive_team" value="1" type="submit">В архив</button>
        </form>
      </div>
    </summary>

    <div class="team-body">
      <div class="team-grid">

        <!-- Левая колонка -->
        <div>
          <div class="card" style="margin-bottom:10px">
            <div class="card-body form-compact">
              <form method="post" class="form-compact" autocomplete="off">
                <?= csrf_field(); ?>
                <input type="hidden" name="team_id" value="<?= $teamId ?>">
                <label>Команда
                  <input name="name" value="<?= h($t['name']) ?>" aria-label="Название команды">
                </label>
                <div class="btn-row">
                  <button class="btn" name="update_team" value="1" type="submit" aria-label="Сохранить команду">Сохранить</button>
                </div>
              </form>
            </div>
          </div>

          <!-- Баланс команды -->
          <div class="card" style="margin-bottom:10px">
            <div class="card-body">
              <h4 style="margin:0 0 10px 0">Баланс команды</h4>
              <div class="metrics">
                <div class="card-mini"><div class="t">Курс</div><div class="v"><?= number_format($fx,2,'.',' ') ?> UAH</div></div>
                <div class="card-mini"><div class="t">Пополнения</div><div class="v">$<?= number_format($topups_usd,2,'.',' ') ?></div></div>
                <div class="card-mini"><div class="t">Списания</div><div class="v"><?= money_uah($spend_uah) ?> (≈ $<?= number_format($spend_usd,2,'.',' ') ?>)</div></div>
                <div class="card-mini"><div class="t">Остаток</div><div class="v"><b>$<?= number_format($remain_usd,2,'.',' ') ?></b> · <?= money_uah($remain_uah) ?></div></div>
              </div>

              <!-- Пополнить -->
              <form method="post" class="form-compact topup-form topup-grid" autocomplete="off">
                <?= csrf_field(); ?>
                <input type="hidden" name="team_id" value="<?= $teamId ?>">
                <input type="hidden" name="team_topup_add" value="1">
                <label>Сумма (USD)
                  <input name="amount_usd" type="number" step="0.01" placeholder="100.00" required inputmode="decimal">
                </label>
                <label>Дата (опц.)
                  <input name="created_at" type="datetime-local" step="1">
                </label>
                <label>Заметка
                  <input name="note" placeholder="Комментарий…">
                </label>
                <button class="btn btn-primary" type="submit">Пополнить</button>
              </form>

              <!-- Последние пополнения -->
              <div style="margin-top:10px">
                <div class="muted" style="margin-bottom:6px">Последние пополнения</div>
                <div class="table-wrap">
                  <table class="table table-borders topups-table" style="width:100%">
                    <thead><tr><th>Дата</th><th class="num">USD</th><th>Заметка</th><th class="num"></th></tr></thead>
                    <tbody>
                    <?php if(!$topups): ?>
                      <tr><td colspan="4" class="muted">Пока нет записей</td></tr>
                    <?php else: foreach($topups as $tp): ?>
                      <tr>
                        <!-- 1-й TD: дата + сумма + заметка (для мобилки этого достаточно) -->
                        <td>
                          <div class="topup-head" style="display:flex;justify-content:space-between;gap:8px;">
                            <span class="topup-date"><?= h(date('d.m.Y H:i', strtotime((string)$tp['created_at']))) ?></span>
                            <span class="topup-amount">$<?= number_format((float)$tp['amount_usd'],2,'.',' ') ?></span>
                          </div>
                          <?php if ((string)$tp['note']!==''): ?>
                            <div class="topup-note" style="color:#94a3b8;word-break:anywhere;"><?= h((string)$tp['note']) ?></div>
                          <?php endif; ?>
                        </td>

                        <!-- 2-й/3-й TD — для десктопа -->
                        <td class="num nowrap">$<?= number_format((float)$tp['amount_usd'],2,'.',' ') ?></td>
                        <td><?= h((string)$tp['note']) ?></td>

                        <!-- 4-й TD — кнопка -->
                        <td class="num actions">
                          <form method="post" onsubmit="return confirm('Удалить пополнение?');" style="display:inline">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="team_topup_delete" value="1">
                            <input type="hidden" name="team_id"  value="<?= $teamId ?>">
                            <input type="hidden" name="topup_id" value="<?= (int)$tp['id'] ?>">
                            <button class="btn btn-danger btn-ghost btn-small" type="submit">Удалить</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                  </table>
                </div>

                <!-- Пагинация по 5 записей -->
                <?php if ($pagesTotal > 1): ?>
                  <div class="pager">
                    <a href="<?= h($mkLink(max(1,$page-1))) ?>" aria-disabled="<?= $page<=1?'true':'false' ?>">← Предыдущие</a>
                    <span class="muted">Стр. <?= (int)$page ?> / <?= (int)$pagesTotal ?></span>
                    <a href="<?= h($mkLink(min($pagesTotal,$page+1))) ?>" aria-disabled="<?= $page>=$pagesTotal?'true':'false' ?>">Следующие →</a>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Telegram чаты команды -->
          <div class="card">
            <div class="card-body form-compact">
              <h4>Telegram чаты команды</h4>
              <form method="post" class="form-compact" style="margin-bottom:12px" autocomplete="off">
                <?= csrf_field(); ?>
                <input type="hidden" name="tt_bind" value="1">
                <input type="hidden" name="team_id" value="<?= $teamId ?>">
                <label>Chat ID
                  <input name="tt_chat_id" type="number" required placeholder="-1001234567890" inputmode="numeric" aria-label="Chat ID телеграм">
                </label>
                <label>Тип
                  <select name="tt_chat_type" aria-label="Тип телеграм-чата">
                    <option value="private">private</option>
                    <option value="group">group</option>
                    <option value="supergroup" selected>supergroup</option>
                    <option value="channel">channel</option>
                  </select>
                </label>
                <label>Название (необязательно)
                  <input name="tt_title" placeholder="Название чата" aria-label="Название чата">
                </label>
                <button class="btn btn-primary" type="submit" aria-label="Привязать чат">Привязать чат</button>
              </form>

              <div class="table-wrap">
                <table class="table table-borders tt-table">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Chat ID</th>
                      <th>Тип</th>
                      <th>Название</th>
                      <th>Статус</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (!$teamChats): ?>
                    <tr><td colspan="6" class="muted">Пока нет привязанных чатов.</td></tr>
                  <?php else: foreach ($teamChats as $c): ?>
                    <tr>
                      <td><?= (int)$c['id'] ?></td>
                      <td><?= h($c['chat_id']) ?></td>
                      <td><?= h($c['chat_type']) ?></td>
                      <td><?= h($c['title']) ?></td>
                      <td>
                        <?php if ((int)$c['is_active']===1): ?>
                          <span class="badge-soft" style="border-color:#16a34a;background:#052e1a;color:#86efac">активен</span>
                        <?php else: ?>
                          <span class="badge-soft">выкл</span>
                        <?php endif; ?>
                      </td>
                      <td class="num" style="white-space:nowrap">
                        <form method="post" style="display:inline">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="tt_toggle" value="1">
                          <input type="hidden" name="team_id" value="<?= $teamId ?>">
                          <input type="hidden" name="chat_id" value="<?= (int)$c['chat_id'] ?>">
                          <input type="hidden" name="active"  value="<?= (int)$c['is_active'] ?>">
                          <button class="btn btn-ghost btn-small" type="submit" aria-label="<?= (int)$c['is_active']? 'Выключить чат' : 'Включить чат' ?>"><?= (int)$c['is_active']? 'Выключить' : 'Включить' ?></button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('Удалить привязку чата?');" aria-label="Удалить привязку чата">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="tt_unbind" value="1">
                          <input type="hidden" name="team_id" value="<?= $teamId ?>">
                          <input type="hidden" name="chat_id" value="<?= (int)$c['chat_id'] ?>">
                          <button class="btn btn-danger btn-ghost btn-small" type="submit">Удалить</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Правая колонка -->
        <div>
          <!-- Блок баеров: добавление + список -->
          <div class="card" style="margin-bottom:10px">
            <div class="card-body">
              <h4 style="margin:0 0 8px 0">Баеры</h4>

              <!-- ДОБАВИТЬ БАЕРА -->
              <form method="post" class="buyer-create-form" autocomplete="off">
                <?= csrf_field(); ?>
                <input type="hidden" name="create_buyer" value="1">
                <input type="hidden" name="team_id" value="<?= $teamId ?>">
                <label>Имя баера
                  <input name="name" placeholder="Например: Ivan" required aria-label="Имя баера">
                </label>
                <label>Telegram (опц.)
                  <input name="telegram_chat_id" placeholder="@nickname или chat_id" aria-label="Telegram баера">
                </label>
                <button class="btn btn-primary" type="submit">Добавить баера</button>
              </form>

              <!-- СПИСОК баеров -->
              <div class="table-wrap">
                <table class="table table-borders buyers-table">
                  <thead>
                    <tr>
                      <th>Баер</th>
                      <th class="num">Сумма по картам (UAH)</th>
                      <th class="num">Итого (USD)</th>
                      <th>Telegram</th>
                      <th class="num"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($buyers as $b):
                      $col = cards_balance_col_ui_local();
                      $sum_b_uah = $col
                        ? (float)db_cell(
                            "SELECT COALESCE(SUM(c.`$col`),0) FROM cards c
                              WHERE c.buyer_id=? AND IFNULL(c.status,'waiting') NOT IN ('archived','processing','await_refund')",
                            [$b['id']]
                          )
                        : 0.0;
                      $sum_b_usd = $fx>0 ? $sum_b_uah/$fx : 0.0;
                    ?>
                    <tr>
                      <td><?= h($b['name']) ?></td>
                      <td class="num"><?= money_uah($sum_b_uah) ?></td>
                      <td class="num">$<?= number_format((float)$sum_b_usd,2,'.',' ') ?></td>
                      <td class="muted"><?= h($b['telegram_chat_id']) ?></td>
                      <td class="num">
                        <form method="post" onsubmit="return confirm('Архивировать баера «<?= h($b['name']) ?>»?')" style="display:inline" aria-label="Архивировать баера <?= h($b['name']) ?>">
                          <?= csrf_field(); ?>
                          <input type="hidden" name="action" value="archive_buyer">
                          <input type="hidden" name="archive_buyer" value="1">
                          <input type="hidden" name="team_id" value="<?= $teamId ?>">
                          <input type="hidden" name="buyer_id" value="<?= (int)$b['id'] ?>">
                          <button class="btn btn-danger btn-ghost" type="submit">Архив</button>
                        </form>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($buyers)): ?>
                    <tr><td colspan="5" class="muted">Баеров пока нет</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="card" style="margin-bottom:10px">
            <div class="card-body">
              <h4 style="margin:0 0 8px 0">Последние холды</h4>
              <table class="table table-borders ops-table" style="width:100%">
                <thead><tr><th>Дата</th><th>Карта</th><th class="num">Сумма</th></tr></thead>
                <tbody>
                <?php if(!$holds): ?>
                  <tr><td colspan="3" class="muted">Холдов нет</td></tr>
                <?php else: foreach($holds as $h): ?>
                  <tr>
                    <td><?= h(date('d.m H:i',strtotime((string)$h['created_at']))) ?></td>
                    <td>****<?= h(card_last4_ui((int)$h['card_id'])) ?></td>
                    <td class="num"><?= money_uah((float)$h['amt']) ?></td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="card-body">
              <h4 style="margin:0 0 8px 0">Последние операции</h4>
              <table class="table table-borders ops-table" style="width:100%">
                <thead><tr><th>Дата</th><th>Тип</th><th>Карта</th><th class="num">Сумма</th></tr></thead>
                <tbody>
                <?php if(!$ops): ?>
                  <tr><td colspan="4" class="muted">Операций нет</td></tr>
                <?php else: foreach($ops as $o): ?>
                  <?php $sign = ($o['type']==='debit' ? '-' : ($o['type']==='topup' ? '+' : '±')); ?>
                  <tr>
                    <td><?= h(date('d.m H:i',strtotime((string)$o['created_at']))) ?></td>
                    <td><?= h($o['type']) ?></td>
                    <td>****<?= h(card_last4_ui((int)$o['card_id'])) ?></td>
                    <td class="num"><?= $sign ?> <?= number_format((float)$o['amt'],2,'.',' ') ?> UAH</td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div><!-- /Правая колонка -->
      </div><!-- /.team-grid -->
    </div><!-- /.team-body -->
  </details>
<?php endforeach; ?>
</div>

<script>
// Аккордеон: по умолчанию закрыт; открыть по якорю
if (location.hash && location.hash.startsWith('#team-')) {
  const det = document.querySelector(location.hash);
  if (det) det.setAttribute('open','open');
}
// Режим "только один открыт"
document.addEventListener('toggle', function(e){
  if (e.target && e.target.matches('.team-accordion') && e.target.open) {
    document.querySelectorAll('.team-accordion').forEach(function(other){
      if (other!==e.target) other.open = false;
    });
  }
}, true);
</script>

<?php include __DIR__.'/_layout_footer.php'; ?>
