<?php
require_once __DIR__.'/../lib/auth.php';
$title='Карты'; $active='cards';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/crypto.php';
require_once __DIR__.'/../lib/log.php';
@require_once __DIR__.'/../lib/limit.php';
@require_once __DIR__.'/../lib/balance.php';

auth_require(); auth_require_admin(); csrf_check();

/* ---------- Флеш ---------- */
if (!function_exists('set_flash')) {
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
  function set_flash(string $type, string $message): void { $_SESSION['__flash'][] = ['type'=>$type,'message'=>$message]; }
}

/* ---------- Лимиты bootstraps ---------- */
if (function_exists('limit_bootstrap_schema')) limit_bootstrap_schema();
if (function_exists('limit_maybe_monthly_reset')) limit_maybe_monthly_reset(null);

/* ---------- Схемные утилиты ---------- */
function db_has_column(string $t,string $c): bool { try { return db_exec("SHOW COLUMNS FROM `{$t}` LIKE ?",[$c])->fetch()?true:false; } catch(Throwable $e){ return false; } }
function ensure_cards_bank_column(): void {
  try {
    $cols = db_exec("SHOW COLUMNS FROM `cards`")->fetchAll(); $have=[]; foreach($cols as $c){$have[$c['Field']]=true;}
    if (empty($have['bank']) && empty($have['bank_name']) && empty($have['bank_type']) && empty($have['issuer'])) {
      db_exec("ALTER TABLE `cards` ADD COLUMN `bank` VARCHAR(32) NULL AFTER `drop_name`");
    }
  } catch (Throwable $e) {}
}
function ensure_cards_status_values(): void {
  try {
    $row = db_row("SHOW COLUMNS FROM `cards` LIKE 'status'"); if(!$row) return;
    $type=strtolower((string)($row['Type']??'')); if(strpos($type,'enum(')===0 && preg_match_all("/'([^']*)'/",$type,$m)){
      $vals=$m[1]; $need=['waiting','in_work','processing','await_refund','archived']; $merged=array_values(array_unique(array_merge($vals,$need)));
      if($merged!==$vals){ $enum=implode("','",array_map(fn($v)=>str_replace("'","\\'",$v),$merged)); db_exec("ALTER TABLE `cards` MODIFY COLUMN `status` ENUM('{$enum}') NOT NULL DEFAULT 'waiting'");}
    }
  } catch (Throwable $e) {}
}
function ensure_drops_columns(): void {
  try { db_exec("CREATE TABLE IF NOT EXISTS `drops`(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, login VARCHAR(64) UNIQUE, pass_hash VARCHAR(255), name VARCHAR(128), is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e){}
  try { db_exec("ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `drop_id` INT NULL AFTER `buyer_id`"); } catch(Throwable $e){}
  try { db_exec("ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `drop_name` VARCHAR(128) NULL AFTER `drop_id`"); } catch(Throwable $e){}
}
/* BIN‑колонки */
function ensure_pan_first_columns(): void {
  try { db_exec("ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `pan_first4` VARCHAR(4) NULL AFTER `pan_last4`"); } catch(Throwable $e){}
  try { db_exec("ALTER TABLE `cards` ADD COLUMN IF NOT EXISTS `pan_first6` VARCHAR(6) NULL AFTER `pan_first4`"); } catch(Throwable $e){}
}

/* ---------- PAN/бренд ---------- */
function card_last4_local(array $c): string {
  if(!empty($c['pan_last4'])) return substr(preg_replace('/\D/','',(string)$c['pan_last4']),-4);
  foreach(['card_number','pan','number'] as $k){ if(!empty($c[$k])){ $d=preg_replace('/\D/','',(string)$c[$k]); if($d!=='') return substr($d,-4); } }
  return '????';
}
function card_first4_local(array $c): string {
  if(!empty($c['pan_first4'])) return substr(preg_replace('/\D/','',(string)$c['pan_first4']),0,4);
  foreach(['card_number','pan','number'] as $k){ if(!empty($c[$k])){ $d=preg_replace('/\D/','',(string)$c[$k]); if(strlen($d)>=4) return substr($d,0,4); } }
  return '????';
}
function card_first6_local(array $c): ?string {
  if(!empty($c['pan_first6'])) { $d=preg_replace('/\D/','',(string)$c['pan_first6']); return strlen($d)>=6?substr($d,0,6):null; }
  foreach(['card_number','pan','number'] as $k){ if(!empty($c[$k])){ $d=preg_replace('/\D/','',(string)$c[$k]); if(strlen($d)>=6) return substr($d,0,6); } }
  return null;
}
function card_brand_from_bin(?string $first6, string $first4): string {
  $f4 = preg_replace('/\D/','',(string)$first4);
  $f6 = $first6 ? preg_replace('/\D/','',(string)$first6) : null;
  if ($f4 !== '' && $f4[0] === '4') return 'VISA';
  if ($f6) { $n6 = (int)$f6; if ($n6 >= 222100 && $n6 <= 272099) return 'Mastercard'; }
  $prefix2 = (int)substr($f4,0,2);
  if ($prefix2 >= 51 && $prefix2 <= 55) return 'Mastercard';
  return '—';
}

/* ---------- Снимки для history ---------- */
function snapshot_payments_for_card_current(int $card_id): void {
  try {
    $cur = db_row("SELECT c.id, c.buyer_id, b.name AS buyer_name, t.id AS team_id, t.name AS team_name
                     FROM cards c
                LEFT JOIN buyers b ON b.id=c.buyer_id
                LEFT JOIN teams  t ON t.id=b.team_id
                    WHERE c.id=?", [$card_id]);
    if ($cur && !empty($cur['buyer_id'])) {
      db_exec("UPDATE payments p
                  SET p.buyer_id_at_op   = COALESCE(p.buyer_id_at_op,   ?),
                      p.team_id_at_op    = COALESCE(p.team_id_at_op,    ?),
                      p.buyer_name_at_op = COALESCE(p.buyer_name_at_op, ?),
                      p.team_name_at_op  = COALESCE(p.team_name_at_op,  ?)
                WHERE p.card_id=? 
                  AND (p.buyer_id_at_op IS NULL OR p.team_id_at_op IS NULL OR p.buyer_name_at_op IS NULL OR p.team_name_at_op IS NULL)",
              [(int)$cur['buyer_id'], (int)$cur['team_id'], (string)$cur['buyer_name'], (string)$cur['team_name'], $card_id]);
    }
  } catch (Throwable $e) {}
}
function snapshot_payments_for_card_before_change(int $card_id): void {
  try {
    $old = db_row("SELECT c.id, c.buyer_id, b.name AS buyer_name, t.id AS team_id, t.name AS team_name
                     FROM cards c
                LEFT JOIN buyers b ON b.id=c.buyer_id
                LEFT JOIN teams  t ON t.id=b.team_id
                    WHERE c.id=?", [$card_id]);
    if ($old && !empty($old['buyer_id'])) {
      db_exec("UPDATE payments p
                  SET p.buyer_id_at_op   = COALESCE(p.buyer_id_at_op,   ?),
                      p.team_id_at_op    = COALESCE(p.team_id_at_op,    ?),
                      p.buyer_name_at_op = COALESCE(p.buyer_name_at_op, ?),
                      p.team_name_at_op  = COALESCE(p.team_name_at_op,  ?)
                WHERE p.card_id=? 
                  AND (p.buyer_id_at_op IS NULL OR p.team_id_at_op IS NULL OR p.buyer_name_at_op IS NULL OR p.team_name_at_op IS NULL)",
              [(int)$old['buyer_id'], (int)$old['team_id'], (string)$old['buyer_name'], (string)$old['team_name'], $card_id]);
    }
  } catch (Throwable $e) {}
}

/* =========================================================
   Пересчёты одной карты
   ========================================================= */
function recalc_limit_one_card(int $card_id): void {
  if (!db_has_column('cards','limit_cap_uah')) return;
  $cap = (float)(db_row("SELECT limit_cap_uah FROM cards WHERE id=?",[$card_id])['limit_cap_uah'] ?? 0);
  if ($cap <= 0) { db_exec("UPDATE cards SET limit_remaining_uah=NULL WHERE id=?",[$card_id]); return; }
  $start = date('Y-m-01 00:00:00');
  $sum_debit = 0.0; $sum_hold = 0.0;
  try {
    $row = db_row("SELECT COALESCE(SUM(CASE WHEN type='debit' THEN amount_uah ELSE 0 END),0) AS sdeb,
                          COALESCE(SUM(CASE WHEN type='hold'  THEN amount_uah ELSE 0 END),0) AS shold
                     FROM payments
                    WHERE card_id=? AND created_at>=? AND IFNULL(is_void,0)=0",[$card_id,$start]);
    if ($row) { $sum_debit=(float)$row['sdeb']; $sum_hold=(float)$row['shold']; }
  } catch (Throwable $e) {}
  $remain = max(0.0, min($cap, round($cap - $sum_debit + $sum_hold, 2)));
  if (db_has_column('cards','limit_remaining_uah')) db_exec("UPDATE cards SET limit_remaining_uah=? WHERE id=?",[$remain,$card_id]);
}
function recalc_balance_one_card(int $card_id): void {
  $sum = 0.0;
  try {
    $row = db_row("SELECT COALESCE(SUM(CASE WHEN type='topup' THEN amount_uah
                                            WHEN type='debit' THEN -amount_uah
                                            ELSE 0 END),0) AS s
                     FROM payments
                    WHERE card_id=? AND IFNULL(is_void,0)=0",[$card_id]);
    if ($row) $sum = (float)$row['s'];
  } catch (Throwable $e) {}
  $col = 'balance_uah';
  try {
    $cols = db_exec("SHOW COLUMNS FROM `cards`")->fetchAll();
    $have=[]; foreach($cols as $c){ $have[$c['Field']]=true; }
    if (!empty($have['balance'])) $col='balance';
    if (!empty($have['current_balance'])) $col='current_balance';
    if (!empty($have['bal_uah'])) $col='bal_uah';
    if (empty($have[$col])) { db_exec("ALTER TABLE `cards` ADD COLUMN `balance_uah` DECIMAL(14,2) NOT NULL DEFAULT 0"); $col='balance_uah'; }
  } catch (Throwable $e) {}
  db_exec("UPDATE cards SET `$col`=? WHERE id=?", [round($sum,2), $card_id]);
}

/* =========================================================
   POST‑обработчики
   ========================================================= */
$isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  /* Создание карты */
  if (isset($_POST['create_card'])) {
    ensure_cards_bank_column(); ensure_cards_status_values(); ensure_drops_columns(); ensure_pan_first_columns();

    $drop_id  = (int)($_POST['drop_id'] ?? 0);
    $drop_rec = $drop_id>0 ? db_row("SELECT * FROM drops WHERE id=? AND is_active=1",[$drop_id]) : null;
    $drop_name= $drop_rec ? $drop_rec['name'] : null;

    $bank      = (string)($_POST['bank'] ?? 'privat');
    $pan_raw   = (string)($_POST['card_number'] ?? '');
    $pan_digits= preg_replace('/\D/','',$pan_raw);
    $pan       = $pan_digits;
    $cvv       = (string)($_POST['cvv'] ?? '');
    $exp_month = (int)($_POST['exp_month'] ?? 12);
    $exp_year  = (int)($_POST['exp_year'] ?? ((int)date('Y')+3));
    $status    = 'waiting';
    $pan_first4= strlen($pan_digits)>=4 ? substr($pan_digits,0,4) : null;
    $pan_first6= strlen($pan_digits)>=6 ? substr($pan_digits,0,6) : null;
    $pan_last4 = strlen($pan_digits)>=4 ? substr($pan_digits,-4) : null;

    $cols_set=[]; try{ foreach(db_exec("SHOW COLUMNS FROM `cards`")->fetchAll() as $c){ $cols_set[$c['Field']]=true; } }catch(Throwable $e){}
    $cols=[]; $place=[]; $params=[];
    if (!empty($cols_set['buyer_id']))   { $cols[]='buyer_id';   $place[]='NULL'; }
    if (!empty($cols_set['drop_id']))    { $cols[]='drop_id';    $place[]= $drop_id>0?'?':'NULL'; if($drop_id>0)$params[]=$drop_id; }
    if (!empty($cols_set['drop_name']))  { $cols[]='drop_name';  $place[]= $drop_name!==null?'?':'NULL'; if($drop_name!==null)$params[]=$drop_name; }
    foreach (['bank','bank_name','bank_type','issuer'] as $cand) { if (!empty($cols_set[$cand])) { $cols[]="`$cand`"; $place[]='?'; $params[]=$bank; break; } }
    if (!empty($cols_set['pan_enc']))    { $cols[]='pan_enc';    $place[]='?'; $params[]=crypto_encrypt($pan); }
    if (!empty($cols_set['pan_last4']))  { $cols[]='pan_last4';  $place[]=$pan_last4!==null?'?':'NULL'; if($pan_last4!==null)$params[]=$pan_last4; }
    if (!empty($cols_set['pan_first4'])) { $cols[]='pan_first4'; $place[]=$pan_first4!==null?'?':'NULL'; if($pan_first4!==null)$params[]=$pan_first4; }
    if (!empty($cols_set['pan_first6'])) { $cols[]='pan_first6'; $place[]=$pan_first6!==null?'?':'NULL'; if($pan_first6!==null)$params[]=$pan_first6; }
    if (!empty($cols_set['cvv_enc']))    { $cols[]='cvv_enc';    $place[]='?'; $params[]=crypto_encrypt($cvv); }
    if (!empty($cols_set['exp_month']))  { $cols[]='exp_month';  $place[]='?'; $params[]=$exp_month; }
    if (!empty($cols_set['exp_year']))   { $cols[]='exp_year';   $place[]='?'; $params[]=$exp_year; }
    if (!empty($cols_set['status']))     { $cols[]='status';     $place[]='?'; $params[]=$status; }
    if (!empty($cols_set['limit_cap_uah']))       { $cols[]='limit_cap_uah';       $place[]='100000'; }
    if (!empty($cols_set['limit_remaining_uah'])) { $cols[]='limit_remaining_uah'; $place[]='100000'; }
    if (!empty($cols_set['limit_last_reset_month'])) { $cols[]='limit_last_reset_month'; $place[]=date('Ym'); }
    if (!empty($cols_set['balance_uah'])) { $cols[]='balance_uah'; $place[]='0'; }
    if (!empty($cols_set['created_at']))  { $cols[]='created_at';  $place[]='NOW()'; }
    if (empty($cols)) { $cols=['created_at']; $place=['NOW()']; }

    try {
      $sql="INSERT INTO `cards`(".implode(',',$cols).") VALUES(".implode(',',$place).")";
      db_exec($sql,$params);
      log_op('card','create', db()->lastInsertId(), ['status'=>$status,'drop_id'=>$drop_id]);
    } catch (Throwable $e) {}
    header('Location:/admin/cards.php'); exit;
  }

  /* Привязать/снять баера */
  if ((isset($_POST['assign_buyer']) || (isset($_POST['action']) && $_POST['action']==='assign_buyer'))) {
    ensure_cards_status_values();
    $card_id  = (int)$_POST['card_id'];
    $buyer_id = (int)$_POST['buyer_id'];
    snapshot_payments_for_card_before_change($card_id);
    $status   = $buyer_id > 0 ? 'in_work' : 'waiting';
    db_exec("UPDATE cards SET buyer_id = NULLIF(?,0), status=? WHERE id=?", [$buyer_id,$status,$card_id]);
    log_op('card','assign_buyer',$card_id,['buyer_id'=>$buyer_id,'status'=>$status]);
    header('Location:/admin/cards.php'); exit;
  }

  /* На оформление */
  if (isset($_POST['set_processing'])) {
    ensure_cards_status_values();
    snapshot_payments_for_card_before_change((int)$_POST['card_id']);
    db_exec("UPDATE cards c LEFT JOIN buyers b ON b.id=c.buyer_id
             SET c.processing_source_buyer_id = COALESCE(c.processing_source_buyer_id, c.buyer_id),
                 c.processing_source_team_id  = COALESCE(c.processing_source_team_id,  b.team_id),
                 c.balance_at_processing_uah  = COALESCE(c.balance_at_processing_uah, IFNULL(c.balance_uah,0)),
                 c.processing_at              = COALESCE(c.processing_at, NOW()),
                 c.buyer_id = NULL,
                 c.status   = 'processing'
           WHERE c.id=?", [(int)$_POST['card_id']]);
    log_op('card','processing',(int)$_POST['card_id'],[]);
    header('Location:/admin/cards.php'); exit;
  }

  /* Архив */
  if (isset($_POST['archive_card'])) {
    ensure_cards_status_values();
    db_exec("UPDATE cards SET status='archived' WHERE id=?", [(int)$_POST['card_id']]);
    log_op('card','archive', (int)$_POST['card_id'], []);
    header('Location:/admin/cards.php'); exit;
  }

  /* Зафиксировать историю */
  if (isset($_POST['snapshot_payments_now'])) {
    $cid = (int)($_POST['card_id'] ?? 0);
    snapshot_payments_for_card_current($cid);
    set_flash('ok','История операций зафиксирована по текущей привязке');
    header('Location:/admin/cards.php'); exit;
  }

  /* AJAX: уменьшение остатка */
  if (isset($_POST['reduce_limit_remaining'])) {
    $card_id = (int)($_POST['card_id'] ?? 0);
    $want    = (float)($_POST['new_remaining'] ?? -1);
    $resp = ['ok'=>false,'message'=>'Ошибка'];
    if ($card_id > 0 && $want >= 0) {
      try {
        $row = db_row("SELECT limit_cap_uah, limit_remaining_uah FROM cards WHERE id=?",[$card_id]);
        $cap = isset($row['limit_cap_uah']) ? (float)$row['limit_cap_uah'] : 100000.0;
        $old = isset($row['limit_remaining_uah']) && $row['limit_remaining_uah']!==null ? (float)$row['limit_remaining_uah'] : $cap;
        $new = min($old, max(0.0, round($want,2))); $new = min($new, $cap);
        if ($new < $old) {
          db_exec("UPDATE cards SET limit_remaining_uah=? WHERE id=?",[$new,$card_id]);
          log_op('card','reduce_limit_remaining',$card_id,['cap'=>$cap,'old_remaining'=>$old,'new_remaining'=>$new,'requested'=>$want]);
          $pct = (int)max(0,min(100, round($cap>0 ? ($new/$cap*100) : 0)));
          $color = ($pct>60?'#22c55e':($pct>=30?'#eab308':($pct>=10?'#f97316':'#ef4444')));
          $resp = ['ok'=>true,'message'=>'Сохранено','left'=>$new,'cap'=>$cap,'pct'=>$pct,'bar'=>$color,
                   'left_fmt'=>number_format($new,2,'.',' ')." грн",'cap_fmt'=>number_format($cap,0,'',' ')];
        } else {
          $ratio=$cap>0?$old/$cap:0; $color = ($ratio>0.6?'#22c55e':($ratio>=0.3?'#eab308':($ratio>=0.1?'#f97316':'#ef4444')));
          $resp = ['ok'=>true,'message'=>'Без изменений','left'=>$old,'cap'=>$cap,
                   'pct'=>(int)max(0,min(100, round($cap>0?($old/$cap*100):0))),
                   'bar'=>$color,'left_fmt'=>number_format($old,2,'.',' ')." грн",'cap_fmt'=>number_format($cap,0,'',' ')];
        }
      } catch (Throwable $e) { $resp = ['ok'=>false,'message'=>'DB error']; }
    } else { $resp = ['ok'=>false,'message'=>'Некорректное значение']; }
    if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($resp, JSON_UNESCAPED_UNICODE); exit; }
    else { header('Location:/admin/cards.php'); exit; }
  }

  /* Иконки: пересчёты */
  if (isset($_POST['recalc_limit_one']))    { $cid = (int)($_POST['card_id'] ?? 0); recalc_limit_one_card($cid);    set_flash('ok','Остаток лимита пересчитан для карты #'.$cid); header('Location:/admin/cards.php'); exit; }
  if (isset($_POST['recalc_balance_one']))  { $cid = (int)($_POST['card_id'] ?? 0); recalc_balance_one_card($cid);  set_flash('ok','Баланс пересчитан для карты #'.$cid); header('Location:/admin/cards.php'); exit; }
}

/* =========================================================
   RENDER
   ========================================================= */
require __DIR__.'/_layout.php';
?>
<?php include __DIR__.'/_flash.php'; ?>
<?php
$filter_buyer_id = (int)($_GET['buyer_id'] ?? 0);

$drops = db_exec("SELECT id,name FROM drops WHERE is_active=1 ORDER BY id DESC")->fetchAll();
$buyers = db_exec("SELECT b.id, b.name, t.name AS team_name
                     FROM buyers b JOIN teams t ON t.id=b.team_id
                    WHERE IFNULL(b.is_archived,0)=0 AND IFNULL(t.is_archived,0)=0
                    ORDER BY t.name, b.name")->fetchAll();

$cards_sql = "SELECT c.*, b.name AS buyer_name, t.name AS team_name, d.name AS drop_title
                FROM cards c
           LEFT JOIN buyers b ON b.id=c.buyer_id
           LEFT JOIN teams  t ON t.id=b.team_id
           LEFT JOIN drops  d ON d.id=c.drop_id
               WHERE IFNULL(c.status,'waiting') NOT IN ('archived','processing','await_refund')";
$params = [];
if ($filter_buyer_id > 0) { $cards_sql .= " AND c.buyer_id = ? "; $params[] = $filter_buyer_id; }
$cards_sql .= " ORDER BY c.id DESC";
$cards = db_exec($cards_sql, $params)->fetchAll();
?>
<style>
/* ===== Локальная косметика для ПК и мобайла ===== */

/* Свёртываемая «Создать карту» */
.card-create details { border-radius:12px; overflow:hidden; }
.card-create details > summary {
  list-style:none; cursor:pointer; padding:12px 14px; background:#0f131a; border-bottom:1px solid #1f2530; font-weight:600;
}
.card-create details > summary::-webkit-details-marker{display:none}
.card-create .create-grid { display:grid; gap:12px; padding:12px; }
@media (min-width: 1100px){
  .card-create .create-grid { grid-template-columns: repeat(6, 1fr); align-items:end; }
  .card-create .span-2 { grid-column: span 2; }
  .card-create .span-3 { grid-column: span 3; }
}
@media (max-width: 1099.98px){
  .card-create .create-grid { grid-template-columns: 1fr 1fr; }
  .card-create .span-2 { grid-column: span 2; }
}
@media (max-width: 720px){
  .card-create .create-grid { grid-template-columns: 1fr; }
  .card-create .span-2, .card-create .span-3 { grid-column: 1 / -1; }
}

/* Компактный фильтр */
.card-filter { padding:12px 14px; }
.card-filter select{ min-width: 240px; }

/* Лимит‑блок */
.rem-form{ display:grid; grid-template-columns: 1fr auto; gap:8px; align-items:center; margin-top:6px; }
.rem-form .left-input{ max-width:100%; }
.rem-form .btn{ min-width:110px; white-space:nowrap; }

/* Прогресс */
.progress-box{ width:100% !important; height:10px; background:#1f2937; border-radius:6px; overflow:hidden; }
.limit-text{ font-size:12px; color:#94a3b8; margin-top:4px; }

/* Кнопки действий — компактные */
.table-actions{ display:flex; gap:8px; flex-wrap:wrap; }
.btn-sm{ padding:6px 10px; font-size:13px; border-radius:8px; min-height:34px; }
.btn-sm.btn-danger{ background:#6e1d26; border-color:#8a2732; }

/* Мобильные карточки */
@media (max-width:1100px){
  .card > .table-wrap { overflow: visible; }
  .table-cards { display:block; border:0; min-width:0 !important; }
  .table-cards thead { display:none; }
  .table-cards tbody{
    display:grid; gap:12px;
    grid-template-columns: 1fr 1fr;
  }
  .table-cards tbody tr{
    position: relative;
    display:grid;
    grid-template-columns: minmax(0,1fr) minmax(0,1fr);
    background: var(--panel, #0f131a);
    border:1px solid #222; border-radius:12px; padding:10px 12px;
    box-shadow: 0 6px 18px rgba(0,0,0,.25);
  }
  .table-cards tbody tr > td{
    display:block;
    padding:6px 0; border:0; vertical-align:top;
  }
  /* Подписи над значениями, короткие */
  .table-cards tbody tr > td::before{
    display:block; margin-bottom:4px;
    content:''; color: #8b93a7; font-size:11px; line-height:1.1; white-space:nowrap;
  }
  .table-cards tbody tr > td:nth-child(2)::before { content:"Команда"; }
  .table-cards tbody tr > td:nth-child(3)::before { content:"Работник"; }
  .table-cards tbody tr > td:nth-child(4)::before { content:"Банк"; }
  .table-cards tbody tr > td:nth-child(5)::before { content:"Номер"; }
  .table-cards tbody tr > td:nth-child(6)::before { content:"Лимит"; }
  .table-cards tbody tr > td:nth-child(7)::before { content:"Баланс"; }
  .table-cards tbody tr > td:nth-child(8)::before { content:"Статус"; }
  .table-cards tbody tr > td:nth-child(9)::before { content:"Действия"; }

  /* #ID чип в правом верхнем углу */
  .table-cards tbody tr > td:nth-child(1){ display:none; }
  .table-cards tbody tr::after{
    content:"# " attr(data-card-id);
    position:absolute; top:8px; right:10px;
    font-size:11px; color:#94a3b8;
    background:#0b1220; border:1px solid #1f2937; border-radius:8px; padding:2px 6px;
  }

  /* Раскладка по зонам внутри карточки */
  .table-cards tbody tr > td:nth-child(2){ grid-column: 1 / -1; } /* Команда */
  .table-cards tbody tr > td:nth-child(3){ grid-column: 1 / 2; }  /* Работник  */
  .table-cards tbody tr > td:nth-child(4){ grid-column: 2 / 3; }  /* Банк  */
  .table-cards tbody tr > td:nth-child(5){ grid-column: 1 / -1; } /* PAN   */
  .table-cards tbody tr > td:nth-child(6){ grid-column: 1 / -1; } /* Лимит */
  .table-cards tbody tr > td:nth-child(7){ grid-column: 1 / 2; }  /* Баланс */
  .table-cards tbody tr > td:nth-child(8){ grid-column: 2 / 3; }  /* Статус */
  .table-cards tbody tr > td:nth-child(9){ grid-column: 1 / -1; } /* Действия */

  /* Кнопки — компактнее на телефонах */
  .btn-sm{ min-height:36px; font-size:14px; }
}
@media (max-width: 820px){
  .table-cards tbody{ grid-template-columns: 1fr; } /* телефоны — по одной карточке */
}

/* --- портировано из payments.php --- */


/* --- портировано из payments.php (tiles) --- */
.tiles-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap:12px; }
.tile {
  display:grid; gap:8px; padding:14px; border:1px solid #222; border-radius:12px;
  background:#0f131a; box-shadow:0 6px 18px rgba(0,0,0,.22);
  cursor:pointer; transition: transform .06s ease, box-shadow .12s ease, border-color .12s ease; text-align:left;
}
.tile:hover { transform: translateY(-1px); box-shadow: 0 8px 22px rgba(0,0,0,.28); border-color:#2c2c2c; }
.tile .t-title { font-weight:600; font-size:16px; }
.tile .t-sub { color:#8b93a7; font-size:12px; }
.tile .t-balance { font-variant-numeric: tabular-nums; font-weight:600; }

/* bank-card visuals */
.tile.bank-card {
  position:relative; display:grid; gap:8px; padding:18px; border-radius:16px;
  border:1px solid rgba(255,255,255,.06);
  background: linear-gradient(135deg, #0f131a 0%, #101723 100%);
  color:#e9eefc; box-shadow:0 12px 30px rgba(0,0,0,.35); overflow:hidden; isolation:isolate;
}
.tile.bank-card .card-top { display:flex; align-items:center; justify-content:space-between; gap:8px; }
.tile.bank-card .bank-name { font-weight:600; letter-spacing:.3px; opacity:.95; }
.tile.bank-card .brand-box { font-weight:900; font-size:18px; letter-spacing:2px; opacity:.9; }
.tile.bank-card .brand-visa { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, 'Helvetica Neue', Arial, sans-serif; }
.tile.bank-card .brand-mastercard .mc circle:first-child { fill:#eb001b; }
.tile.bank-card .brand-mastercard .mc circle:last-child { fill:#f79e1b; }
.tile.bank-card .pan { font-size:20px; letter-spacing:2px; margin:10px 0 4px; font-weight:700; }
.tile.bank-card .owner { color:#9aa3b2; font-size:12px; }
.tile.bank-card .progress-box { background: rgba(255,255,255,.12); border-radius: 8px; overflow:hidden; height:8px; }
.tile.bank-card .limit-text { font-size:12px; opacity:.85; margin-top:6px; }
.tile.bank-card:hover { transform: translateY(-2px) scale(1.01); }
.tile.bank-card::before{
  content:''; position:absolute; right:-20%; top:-40%;
  height:160%; width:60%;
  background: radial-gradient(60% 60% at 50% 50%, rgba(255,255,255,.08), transparent 70%);
  transform: rotate(25deg); pointer-events:none;
}
.tile.bank-card::after{
  content:''; position:absolute; right:-40%; top:-80%;
  height:260%; width:40%;
  background: linear-gradient(120deg, transparent 10%, rgba(255,255,255,.13) 50%, transparent 90%);
  transform: rotate(25deg); pointer-events:none;
}
.tile.bank-card.bank-privat { box-shadow: 0 12px 30px rgba(31,191,74,.18), 0 6px 18px rgba(0,0,0,.35); }
.tile.bank-card.bank-mono { box-shadow: 0 12px 30px rgba(0,0,0,.35); }
.tile.bank-card.bank-other { box-shadow: 0 12px 30px rgba(30,58,138,.18), 0 6px 18px rgba(0,0,0,.35); }

/* === Fix: Real plastic card proportions (ID-1: 85.60 × 53.98 mm ≈ 1.586:1) === */
.tile.bank-card .card-face{
  position: relative;
  aspect-ratio: 85.6 / 53.98; /* modern browsers */
  width: 100%;
  border-radius: 14px;
  padding: 14px 16px;
  background: linear-gradient(135deg, #0f131a 0%, #101723 100%);
  border: 1px solid rgba(255,255,255,.06);
  box-shadow: 0 12px 30px rgba(0,0,0,.35);
  display: grid;
  align-content: start;
  gap: 6px;
  overflow: hidden; /* ensure content stays within card face */
}
/* keep brand and PAN readable inside the face */
.tile.bank-card .card-face .card-top{ display:flex; align-items:center; justify-content:space-between; gap:8px; }
.tile.bank-card .card-face .pan{ font-size:18px; letter-spacing:2px; margin:8px 0 2px; font-weight:700; }
.tile.bank-card .card-face .owner{ font-size:12px; opacity:.9; }
.tile.bank-card .card-face .balance{ font-size:13px; }
.tile.bank-card .card-face .progress-box{ height:6px; }
.tile.bank-card .card-face .limit-text{ font-size:11px; margin-top:4px; }
/* spacing from the face to the controls below */
.tile.bank-card .card-face + *{ margin-top:10px; }
@supports not (aspect-ratio: 1){
  /* Fallback for very old browsers: reserve height via padding */
  .tile.bank-card .card-face{ position: relative; }
  .tile.bank-card .card-face::before{ content:""; display:block; padding-bottom: calc(100% / 1.586); }
}

/* Limit glare/shine effects to the card face only */
.tile.bank-card::before,
.tile.bank-card::after{ display:none; }
.tile.bank-card .card-face::before{
  content:''; position:absolute; right:-20%; top:-40%;
  height:160%; width:60%;
  background: radial-gradient(60% 60% at 50% 50%, rgba(255,255,255,.08), transparent 70%);
  transform: rotate(25deg); pointer-events:none;
}
.tile.bank-card .card-face::after{
  content:''; position:absolute; right:-40%; top:-80%;
  height:260%; width:40%;
  background: linear-gradient(120deg, transparent 10%, rgba(255,255,255,.13) 50%, transparent 90%);
  transform: rotate(25deg); pointer-events:none;
}
</style>
<script>window.CSRF_TOKEN='<?=h(csrf_token())?>';</script>

<!-- Создание карты (сворачиваемая панель) -->
<div class="card card-create">
  <details>
    <summary>Создать карту</summary>
    <form method="post" autocomplete="off">
      <?= csrf_field(); ?>
      <div class="create-grid">
        <label class="span-2">Работник
          <select name="drop_id">
            <option value="0">— не указано —</option>
            <?php foreach($drops as $d): ?><option value="<?=$d['id']?>"><?=h($d['name'])?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Банк
          <select name="bank" required>
            <option value="privat">ПриватБанк</option>
            <option value="mono">МоноБанк</option>
          </select>
        </label>
        <label class="span-2">Номер карты
          <input name="card_number" placeholder="4111 1111 1111 1111" required>
        </label>
        <label>CVV
          <input name="cvv" maxlength="4" required>
        </label>
        <div class="form-row span-2">
          <label>Месяц истечения
            <input type="number" name="exp_month" min="1" max="12" value="12">
          </label>
          <label>Год истечения
            <input type="number" name="exp_year" min="<?=date('Y')?>" max="<?=date('Y')+10?>" value="<?=date('Y')+3?>">
          </label>
        </div>
        <div><button class="btn btn-primary" name="create_card" value="1">Создать карту</button></div>
      </div>
    </form>
  </details>
</div>

<!-- Фильтр -->
<div class="card card-filter">
  <form method="get" class="form-row" style="margin:0">
    <label>Фильтр: Команда → Баер
      <select name="buyer_id" onchange="this.form.submit()">
        <option value="">Все</option>
        <?php foreach ($buyers as $b): ?>
          <option value="<?=$b['id']?>" <?=$filter_buyer_id==$b['id']?'selected':''?>><?=h($b['team_name'].' → '.$b['name'])?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
</div>

<!-- Список карт -->
<div class="card">
  <div class="card-body">
<div class="tiles-grid mt12">
  <?php foreach($cards as $c):
    $calcStatus = ((int)($c['buyer_id'] ?? 0) > 0) ? 'in_work' : 'waiting';
    $bankLabel = bank_label_from_row($c);
    $bankClass = ($bankLabel==='Приват'?'bank-privat':($bankLabel==='Моно'?'bank-mono':'bank-other'));

    $first4 = card_first4_local($c);
    $first6 = card_first6_local($c);
    $last4  = card_last4_local($c);
    $brand  = card_brand_from_bin($first6, $first4);

    $cap=isset($c['limit_cap_uah'])?(float)$c['limit_cap_uah']:100000.0;
    $left = isset($c['limit_remaining_uah']) && $c['limit_remaining_uah']!==null ? (float)$c['limit_remaining_uah'] : $cap;
    $pct =(int)max(0,min(100,round($cap>0?($left/$cap*100):0)));
    $barColor = ($pct>60?'#22c55e':($pct>=30?'#eab308':($pct>=10?'#f97316':'#ef4444')));

    // ===== Доп.метрика: остаток до закрытия (2× cap) — за текущий и предыдущий месяц =====
    $cap2 = $cap * 2;
    $start2 = date('Y-m-01 00:00:00', strtotime('first day of -1 month')); // начало прошлого месяца
    $sum_debit2 = 0.0; $sum_hold2 = 0.0;
    try {
      $r2 = db_row("SELECT COALESCE(SUM(CASE WHEN type='debit' THEN amount_uah ELSE 0 END),0) AS sdeb,
                           COALESCE(SUM(CASE WHEN type='hold'  THEN amount_uah ELSE 0 END),0) AS shold
                      FROM payments
                     WHERE card_id=? AND created_at>=? AND IFNULL(is_void,0)=0",[$c['id'],$start2]);
      if ($r2) { $sum_debit2 = (float)$r2['sdeb']; $sum_hold2 = (float)$r2['shold']; }
    } catch (Throwable $e) {}
    $left2 = max(0.0, min($cap2, round($cap2 - $sum_debit2 + $sum_hold2, 2)));

    // Бренд визуал
    $brandHtml = '';
    $brandLower = strtolower((string)$brand);
    if (strpos($brandLower,'master')!==false) {
        $brandHtml = '<span class="brand-box brand-mastercard" aria-label="Mastercard" title="Mastercard">'
                   . '<svg class="mc" width="44" height="28" viewBox="0 0 48 32" aria-hidden="true">'
                   . '<circle cx="19" cy="16" r="10"></circle>'
                   . '<circle cx="29" cy="16" r="10"></circle>'
                   . '</svg></span>';
        $brandClass = 'brand-mastercard';
    } elseif (strpos($brandLower,'visa')!==false) {
        $brandHtml = '<span class="brand-box brand-visa" aria-label="Visa" title="Visa">VISA</span>';
        $brandClass = 'brand-visa';
    } else {
        $brandHtml = '<span class="brand-box">CARD</span>';
        $brandClass = 'brand-generic';
    }
  ?>
    <div class="tile bank-card <?=$brandClass?> <?=$bankClass?>" data-card-id="<?=$c['id']?>">
      <div class="card-face">
      <div class="card-top">
        <div class="bank-name"><?=h($bankLabel)?></div>
        <?=$brandHtml?>
      </div>
      <div class="pan mono"><?=h(mask_pan_last4(card_last4_local($c)))?></div>
      <div class="t-title"><?=h(($c['team_name']??'').(!empty($c['buyer_name'])?' → '.$c['buyer_name']:'') ?: '—')?></div>
      <div class="owner">Работник: <?=h(card_drop_from_row($c))?></div>
      <div class="balance t-balance">Баланс: <span class="mono"><?=money_uah($c['balance_uah'] ?? 0)?></span></div>

      <div class="t-sub">Лимит (остаток)</div>
      <div class="progress-box"><div class="bar" style="width:<?=$pct?>%;height:100%;background:<?=$barColor?>;"></div></div>
      <div class="limit-text">Остаток: <span class="left-text"><?=number_format($left,2,'.',' ')?> грн</span>
        (cap <span class="cap-text"><?=number_format($cap,0,'',' ')?></span>)
      </div>
      <div class="limit-text muted-sm">До закрытия (2× cap):
        <span class="left2-text"><?=number_format($left2,2,'.',' ')?> грн</span>
        (2×cap <span class="cap2-text"><?=number_format($cap2,0,'',' ')?></span>)
      </div>
      </div><!-- /card-face -->


      <!-- Назначение баера -->
      <div class="inline-select" style="margin-top:6px">
        <form method="post" action="/admin/cards.php">
          <?= csrf_field(); ?>
          <input type="hidden" name="card_id" value="<?=$c['id']?>">
          <input type="hidden" name="action" value="assign_buyer">
          <select name="buyer_id" onchange="this.form.submit()">
            <option value="0">(Нет)</option>
            <?php foreach ($buyers as $b): ?>
              <option value="<?=$b['id']?>" <?= (int)($c['buyer_id']??0)==(int)$b['id']?'selected':''?>><?=h($b['team_name'].' → '.$b['name'])?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <!-- Установка нового остатка -->
      <form method="post" class="rem-form ajax-rem" action="/admin/cards.php">
        <?= csrf_field(); ?>
        <input type="hidden" name="card_id" value="<?=$c['id']?>">
        <input type="hidden" name="reduce_limit_remaining" value="1">
        <input type="number" class="left-input" name="new_remaining"
               step="0.01" min="0.00" max="<?= number_format($left,2,'.','') ?>"
               value="<?= number_format($left,2,'.','') ?>" title="Новый остаток (≤ текущего)">
        <button class="btn btn-sm">Установить</button>
      </form>

      <!-- Иконки‑действия рядом с лимитом -->
      <div class="action-icons" style="display:flex;gap:8px;margin-top:6px">
        <form method="post" class="icon-form" title="Пересчитать лимит">
          <?= csrf_field(); ?><input type="hidden" name="card_id" value="<?=$c['id']?>">
          <button class="icon-btn" name="recalc_limit_one" value="1" aria-label="Пересчитать лимит">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 6v-2l-3 3 3 3V8c2.757 0 5 2.243 5 5a5 5 0 0 1-5 5 5.002 5.002 0 0 1-4.899-4H5.062A6.998 6.998 0 0 0 12 20a7 7 0 0 0 0-14zM6.343 7.757l1.414-1.414A7 7 0 0 0 5 13h2a5 5 0 0 1 5-5V6l3 3-3 3V10a3 3 0 0 0-3 3H5a7 7 0 0 1 1.343-5.243z" fill="currentColor"/></svg>
          </button>
        </form>
        <form method="post" class="icon-form" title="Пересчитать баланс">
          <?= csrf_field(); ?><input type="hidden" name="card_id" value="<?=$c['id']?>">
          <button class="icon-btn" name="recalc_balance_one" value="1" aria-label="Пересчитать баланс">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M4 5h16a1 1 0 0 1 1 1v3H3V6a1 1 0 0 1 1-1zm-1 6h18v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-8zm4 2v2h2v-2H7zm4 0v2h6v-2h-6z" fill="currentColor"/></svg>
          </button>
        </form>
        <form method="post" class="icon-form" title="Зафиксировать историю">
          <?= csrf_field(); ?><input type="hidden" name="card_id" value="<?=$c['id']?>">
          <button class="icon-btn" name="snapshot_payments_now" value="1" aria-label="Зафиксировать историю">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zm4 7h6v2H9v-2z" fill="currentColor"/></svg>
          </button>
        </form>
      </div>

      <!-- Статус + действия -->
      <div class="table-actions" style="margin-top:8px">
        <?php if ($calcStatus==='in_work'): ?>
          <span class="badge status-in_work">in_work</span>
        <?php elseif (($c['status']??'')==='processing'): ?>
          <span class="badge status-processing">processing</span>
        <?php elseif (($c['status']??'')==='waiting'): ?>
          <span class="badge status-waiting">waiting</span>
        <?php else: ?>
          <span class="badge"><?=h($c['status']??'—')?></span>
        <?php endif; ?>

        <form method="post">
          <?= csrf_field(); ?><input type="hidden" name="card_id" value="<?=$c['id']?>">
          <button class="btn btn-sm" name="set_processing" value="1" title="На оформление">На оформлении</button>
        </form>
        <form method="post" onsubmit="return confirm('Отправить карту #<?=$c['id']?> в архив?')">
          <?= csrf_field(); ?><input type="hidden" name="card_id" value="<?=$c['id']?>">
          <button class="btn btn-danger btn-sm" name="archive_card" value="1" title="Архивировать">Архив</button>
        </form>
        <button class="btn btn-sm" type="button" onclick="goPayments(<?=$c['id']?>)" title="Оплаты/пополнения">Оплаты</button>
      </div>
    </div>
  <?php endforeach; if(empty($cards)): ?>
    <div class="muted">Карт нет</div>
  <?php endif; ?>
</div>
  </div>
</div>

<script>
/* быстрый переход в Оплаты/Пополнения */
function goPayments(cardId){ location.href='/admin/payments.php?card='+encodeURIComponent(cardId); }

/* AJAX: уменьшение остатка лимита */
document.addEventListener('submit', async (e)=>{
  const f = e.target;
  if (!f.classList || !f.classList.contains('ajax-rem')) return;
  e.preventDefault();
  const row = f.closest('.tile');
  const data = new FormData(f);
  data.append('X-Requested-With','XMLHttpRequest');

  const resp = await fetch(f.action || location.href, {
    method:'POST', body: data, headers: { 'X-Requested-With':'XMLHttpRequest' }
  }).then(r=>r.json()).catch(()=>({ok:false}));

  if(!resp || !resp.ok) { alert(resp && resp.message ? resp.message : 'Ошибка'); return; }

  /* обновляем UI строки */
  const leftText = row.querySelector('.left-text');
  const capText  = row.querySelector('.cap-text');
  const bar      = row.querySelector('.progress-box .bar');
  if (leftText) leftText.textContent = resp.left_fmt || (resp.left+' грн');
  if (capText && resp.cap_fmt) capText.textContent = resp.cap_fmt;
  if (bar) { bar.style.width = (resp.pct||0)+'%'; bar.style.background = resp.bar || '#22c55e'; }

  /* обновляем input */
  const inp = f.querySelector('input[name="new_remaining"]');
  if (inp) { inp.value = (resp.left || 0).toFixed(2); inp.max = inp.value; }
});
</script>

<?php include __DIR__.'/_layout_footer.php'; ?>
