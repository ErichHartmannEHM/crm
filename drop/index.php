<?php
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/drop_auth.php';
@require_once __DIR__.'/../lib/limit.php';
@require_once __DIR__.'/../lib/fx.php';
@require_once __DIR__.'/../lib/telegram.php';

// Global toggle to mute admin-chat notifications
if (!defined('ADMIN_NOTIFICATIONS_ENABLED')) { define('ADMIN_NOTIFICATIONS_ENABLED', false); }

drop_require();
$did = drop_id();

/* ---------- helpers / schema ---------- */
function db_has_column(string $t,string $c): bool {
  try { return db_exec("SHOW COLUMNS FROM `{$t}` LIKE ?",[$c])->fetch()?true:false; } catch(Throwable $e){ return false; }
}
function cards_balance_column(): string {
  foreach (['balance_uah','balance','current_balance','bal_uah'] as $cand) if (db_has_column('cards',$cand)) return $cand;
  try { db_exec("ALTER TABLE `cards` ADD COLUMN `balance_uah` DECIMAL(14,2) NOT NULL DEFAULT 0"); } catch(Throwable $e){}
  return 'balance_uah';
}
function bank_label_from_row_local(array $row): string {
  foreach (['bank','bank_name','bank_type','issuer'] as $k) {
    if (!empty($row[$k])) {
      $v = strtolower((string)$row[$k]);
      if (strpos($v,'privat')!==false) return 'Приват';
      if (strpos($v,'mono')!==false)   return 'Моно';
      return ucfirst($v);
    }
  }
  return '—';
}
function ensure_drops_tg_nick(): void {
  try { if (!db_has_column('drops','tg_nick')) db_exec("ALTER TABLE drops ADD COLUMN tg_nick VARCHAR(64) NULL AFTER name"); } catch(Throwable $e){}
}

$bal_col = cards_balance_column();
ensure_drops_tg_nick();

/* ---------- load drop ---------- */
$drop = db_row("SELECT * FROM drops WHERE id=? AND is_active=1", [$did]);
if (!$drop) { drop_logout(); header('Location:/drop/login.php'); exit; }
$drop_name = (string)$drop['name'];
$drop_nick = trim((string)($drop['tg_nick'] ?? ''));

/* ---------- POST (only debit/hold) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $cid  = (int)($_POST['card_id'] ?? 0);
  $type = (string)($_POST['type'] ?? '');
  $amt  = (float)($_POST['amount_uah'] ?? 0);

  if (!in_array($type,['debit','hold'],true)) { exit('forbidden'); }
  if ($amt <= 0) { exit('bad amount'); }

  // карта должна принадлежать работнику и быть в активных статусах
  $card = db_row("SELECT c.*, d.name AS drop_name
                    FROM cards c
               LEFT JOIN drops d ON d.id=c.drop_id
                   WHERE c.id=? AND c.drop_id=? AND IFNULL(c.status,'waiting') NOT IN ('archived','processing','await_refund')",
                 [$cid,$did]);
  if (!$card) { exit('no access'); }

  // Лимит
  $limit_left = null;
  if (function_exists('limit_apply_operation')) {
    if ($type==='debit') {
      $lr = limit_apply_operation($cid,'debit',$amt);
      if (!$lr['ok']) { exit('limit exceeded'); }
      $limit_left = (float)($lr['limit_remaining'] ?? null);
    } else { // hold
      $lr = limit_apply_operation($cid,'hold',$amt);
      $limit_left = (float)($lr['limit_remaining'] ?? null);
    }
  }

  // Баланс карты
  if ($type==='debit') {
    db_exec("UPDATE cards SET `$bal_col` = IFNULL(`$bal_col`,0) - ? WHERE id=?",[$amt,$cid]);
  } else { // hold
    db_exec("UPDATE cards SET `$bal_col` = IFNULL(`$bal_col`,0) + ? WHERE id=?",[$amt,$cid]);
  }

  // Запись платежа + получим ID операции
  try {
    db_exec("INSERT INTO payments(card_id,type,amount_uah,note,created_at)
             VALUES (?,?,?,?,NOW())",[$cid,$type,$amt,'by drop #'.$did.' ('.$drop_name.')']);
    $payment_id = (int)db()->lastInsertId();
  } catch(Throwable $e){ $payment_id = 0; }

  // Контекст для уведомлений: баер/команда/банк/баланс
  $ctx = db_row("SELECT c.*,
                        b.id   AS buyer_id,
                        b.name AS buyer_name,
                        t.name AS team_name
                   FROM cards c
              LEFT JOIN buyers b ON b.id=c.buyer_id
              LEFT JOIN teams  t ON t.id=b.team_id
                  WHERE c.id=?",[$cid]);

  $last4     = card_last4_from_row($ctx);
  $bal_after = (float)($ctx[$bal_col] ?? 0);
  $opLabel   = ($type==='debit' ? 'Списание' : 'Холд (возврат)');
  $sign      = ($type==='debit' ? '−' : '+');
  $ts        = (new DateTime('now'))->format('d.m.Y H:i');
  $buyerNm   = trim((string)($ctx['buyer_name'] ?? ''));
  $teamNm    = trim((string)($ctx['team_name'] ?? ''));
  $bankLabel = bank_label_from_row_local($ctx);
  $headerTB  = ($teamNm!=='' || $buyerNm!=='')
              ? "🏷 <b>".h($teamNm).($buyerNm!==''?' → '.h($buyerNm):'')."</b>\n" : '';

  /* ---- Баеру: БЕЗ упоминания работника ---- */
  $msgBuyer = "💳 <b>Карта</b> •••• {$last4}  |  <b>{$bankLabel}</b>\n"
            . $headerTB
            . "🔹 <b>Операция:</b> {$opLabel}\n"
            . "💰 <b>Сумма:</b> {$sign}".number_format($amt,2,'.',' ')." грн\n"
            . "🏦 <b>Баланс карты:</b> ".number_format($bal_after,2,'.',' ')." грн\n"
            . "🧮 <b>Остаток лимита:</b> ".($limit_left!==null?number_format((float)$limit_left,2,'.',' ')." грн":"—")."\n"
            . "🆔 <b>ID операции:</b> ".($payment_id ?: '—')."\n"
            . "⏱️ <b>Время:</b> {$ts}";
  telegram_notify_buyer_by_card($cid, $msgBuyer);

  /* ---- Админу: с ником работника (@nick) или именем ---- */
  $nickLine = $drop_nick !== '' ? '@'.ltrim($drop_nick,'@') : ('Работник '.h($drop_name));
  $msgAdmin = "👑 <b>ADMIN</b>\n"
            . "💳 <b>Карта</b> •••• {$last4}  |  <b>{$bankLabel}</b>\n"
            . $headerTB
            . "🔹 <b>Операция:</b> {$opLabel}\n"
            . "💰 <b>Сумма:</b> {$sign}".number_format($amt,2,'.',' ')." грн\n"
            . "🏦 <b>Баланс карты:</b> ".number_format($bal_after,2,'.',' ')." грн\n"
            . "🧮 <b>Остаток лимита:</b> ".($limit_left!==null?number_format((float)$limit_left,2,'.',' ')." грн":"—")."\n"
            . "🆔 <b>ID операции:</b> ".($payment_id ?: '—')."\n"
            . "👤 <b>Внёс:</b> {$nickLine}\n"
            . "⏱️ <b>Время:</b> {$ts}";
  telegram_notify_admin($msgAdmin);

  header('Location:/drop/index.php?ok=1'); exit;
}

/* ---------- DATA ---------- */
/* Список карт работника */
$cards = db_exec("SELECT c.* FROM cards c
                   WHERE c.drop_id=? AND IFNULL(c.status,'waiting') NOT IN ('archived','processing','await_refund')
                ORDER BY c.id DESC",[$did])->fetchAll();
/* Лог последних операций по картам работника (ID платежа) */
$ops = db_exec("SELECT p.id AS pid, p.created_at, p.type, p.amount_uah, c.*
                  FROM payments p
             JOIN cards c ON c.id=p.card_id
                 WHERE c.drop_id=?
              ORDER BY p.id DESC LIMIT 100",[$did])->fetchAll();
?>
<!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Работник — Операции (<?= h($drop_name) ?>)</title>
<link rel="stylesheet" href="/assets/app.css">
<style>
  .drop-header { display:flex; align-items:center; justify-content:space-between; gap:12px; }
  .drop-form   { display:grid; grid-template-columns: 1.4fr 1fr 1fr auto; gap:12px; }
  @media (max-width: 980px){ .drop-form { grid-template-columns: 1fr; } }
  .w-100 { width:100%; }
</style>
</head>
<body class="dark">
<div class="layout"><main class="content">

<div class="card">
  <div class="card-body">
    <div class="drop-header">
      <h2 style="margin:0">Операции (<?= h($drop_name) ?>)</h2>
      <a class="btn" href="/drop/login.php?logout=1">Выйти</a>
    </div>

    <form method="post" class="drop-form" style="margin-top:12px">
      <?= csrf_field(); ?>
      <label>Карта
        <select name="card_id" required class="w-100">
          <option value="">— выберите карту —</option>
          <?php foreach($cards as $c): $l4=card_last4_from_row($c); ?>
          <option value="<?= (int)$c['id'] ?>">•••• <?= h($l4) ?> (ID #<?= (int)$c['id'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Тип
        <select name="type" required class="w-100">
          <option value="debit">Списание</option>
          <option value="hold">Холд</option>
        </select>
      </label>

      <label>Сумма, грн
        <input name="amount_uah" type="number" step="0.01" min="0.01" required class="w-100">
      </label>

      <div style="align-self:end">
        <button class="btn btn-primary">Добавить</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th class="num">ID</th>
          <th>Дата</th>
          <th>Карта</th>
          <th>Тип</th>
          <th class="num">Сумма</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($ops as $r):
        $op=strtolower($r['type']); $l4=card_last4_from_row($r);
      ?>
        <tr>
          <td class="num"><?= (int)$r['pid'] ?></td>
          <td class="muted"><?= h($r['created_at']) ?></td>
          <td class="td-mono"><?= mask_pan_last4($l4) ?></td>
          <td><span class="badge <?= $op==='debit'?'status-processing':'status-await' ?>"><?= h($op) ?></span></td>
          <td class="num"><?= money_uah($r['amount_uah']) ?></td>
        </tr>
      <?php endforeach; if(empty($ops)): ?>
        <tr><td colspan="5" class="muted">Пока нет операций</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</main></div>
</body></html>
