<?php
require_once __DIR__.'/../lib/auth.php';
$title = 'Панель управления — Напоминалка';
$active = 'dashboard';
require __DIR__.'/_layout.php';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/fx.php';
require_once __DIR__.'/../lib/settings.php';
@require_once __DIR__.'/../lib/team_balance.php'; // важно: считаем остаток как в teams.php

auth_require(); auth_require_admin();

/* ================= Helpers ================= */

function db_table_exists(string $table): bool {
  try { return (int)db_exec("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", [$table])->fetchColumn() > 0; }
  catch (Throwable $e) { return false; }
}
function db_has_column_ex(string $table, string $column): bool {
  try { return (int)db_exec("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", [$table, $column])->fetchColumn() > 0; }
  catch (Throwable $e) { return false; }
}
function fmt_uah($n): string { return number_format((float)$n, 2, '.', ' ').' грн'; }
function fmt_usd($n): string { return '$'.number_format((float)$n, 2, '.', ' '); }
function fmt_dt(?string $dt): string { return $dt ? date('d.m H:i', strtotime($dt)) : '—'; }
function tg_link_from_nick(?string $nick): ?string {
  if (!$nick) return null; $n = ltrim(trim((string)$nick), '@'); if ($n==='') return null; return 'https://t.me/'.$n;
}

/* ================= Teams notifications (точно как на teams.php) ================= */
$teams = db_exec("SELECT id, name FROM teams WHERE IFNULL(is_archived,0)=0 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$teams_low = [];
foreach ($teams as $t) {
  $tid = (int)$t['id'];
  if (function_exists('team_balance_calc')) {
    $calc = team_balance_calc($tid, '', '', true); // как в teams.php
    $bal  = (float)($calc['remain_usd'] ?? 0);
  } else {
    // крайний случай: fallback на teams.balance_usd
    $bal = (float)db_exec("SELECT balance_usd FROM teams WHERE id=?",[$tid])->fetchColumn();
  }
  if ($bal < 500) {
    $t['balance_calc'] = $bal;
    $teams_low[] = $t;
  }
}

/* ================= Cards blocks (как раньше) ================= */

/* Cards: low balance (< 10 000 UAH) */
$cards_low_balance = db_exec("
  SELECT c.id, c.pan_last4, c.balance_uah,
         b.name AS buyer_name, t.id AS team_id, t.name AS team_name
    FROM cards c
    LEFT JOIN buyers b ON c.buyer_id=b.id
    LEFT JOIN teams  t ON b.team_id=t.id
   WHERE IFNULL(c.status,'waiting') <> 'archived'
     AND c.balance_uah < 10000
ORDER BY c.balance_uah ASC, c.id ASC
")->fetchAll();

/* Cards: low remaining limit (< 20 000 UAH) */
$has_remaining_col = db_has_column_ex('cards','limit_remaining_uah');
$sql_limit = "
  SELECT c.id, c.pan_last4,
         ".($has_remaining_col ? "COALESCE(c.limit_remaining_uah, c.limit_cap_uah)" :
         "GREATEST(0, c.limit_cap_uah - COALESCE(p.sdeb,0) + COALESCE(p.shold,0))")." AS limit_left_uah,
         c.limit_cap_uah,
         b.name AS buyer_name, t.id AS team_id, t.name AS team_name
    FROM cards c
    LEFT JOIN buyers b ON c.buyer_id=b.id
    LEFT JOIN teams  t ON b.team_id=t.id
    ".($has_remaining_col ? "" : "
    LEFT JOIN (
      SELECT card_id,
             SUM(CASE WHEN type='debit' AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') AND IFNULL(is_void,0)=0 THEN amount_uah ELSE 0 END) AS sdeb,
             SUM(CASE WHEN type='hold'  AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') AND IFNULL(is_void,0)=0 THEN amount_uah ELSE 0 END) AS shold
        FROM payments
       GROUP BY card_id
    ) p ON p.card_id=c.id
    ")."
   WHERE IFNULL(c.status,'waiting') <> 'archived'
     AND IFNULL(c.limit_cap_uah,0) > 0
HAVING limit_left_uah < 20000
ORDER BY limit_left_uah ASC, c.id ASC
";
$cards_low_limit = db_exec($sql_limit)->fetchAll();

/* Cards: no operations > 24h (link to drop Telegram) */
$cards_idle = db_exec("
  SELECT c.id, c.pan_last4,
         MAX(p.created_at) AS last_op_at,
         b.name AS buyer_name, t.id AS team_id, t.name AS team_name
    FROM cards c
    LEFT JOIN buyers b ON c.buyer_id=b.id
    LEFT JOIN teams  t ON b.team_id=t.id
    LEFT JOIN payments p ON p.card_id=c.id AND IFNULL(p.is_void,0)=0
   WHERE IFNULL(c.status,'waiting') <> 'archived'
GROUP BY c.id, c.pan_last4, b.name, t.id, t.name
HAVING MAX(p.created_at) IS NULL OR MAX(p.created_at) < (NOW() - INTERVAL 24 HOUR)
ORDER BY last_op_at ASC
LIMIT 200
")->fetchAll();

/* batch lookup drop telegram nicks for idle cards */
$tg_by_card = [];
if (db_table_exists('drops')) {
  if (db_table_exists('drop_cards')) {
    $ids = array_map(fn($r)=>(int)$r['id'], $cards_idle);
    if ($ids) {
      $place = implode(',', array_fill(0, count($ids), '?'));
      $rows = db_exec("
        SELECT dc.card_id, d.tg_nick
          FROM drop_cards dc
          JOIN drops d ON d.id=dc.drop_id
         WHERE dc.card_id IN ($place)
      ", $ids)->fetchAll();
      foreach ($rows as $r) if (!empty($r['tg_nick']) && !isset($tg_by_card[(int)$r['card_id']])) $tg_by_card[(int)$r['card_id']] = (string)$r['tg_nick'];
    }
  }
  if (!$tg_by_card && db_has_column_ex('cards','drop_id')) {
    $ids = array_map(fn($r)=>(int)$r['id'], $cards_idle);
    if ($ids) {
      $place = implode(',', array_fill(0, count($ids), '?'));
      $rows = db_exec("
        SELECT c.id AS card_id, d.tg_nick
          FROM cards c
          LEFT JOIN drops d ON d.id=c.drop_id
         WHERE c.id IN ($place)
      ", $ids)->fetchAll();
      foreach ($rows as $r) if (!empty($r['tg_nick']) && !isset($tg_by_card[(int)$r['card_id']])) $tg_by_card[(int)$r['card_id']] = (string)$r['tg_nick'];
    }
  }
  if (db_has_column_ex('cards','drop_name') && !$tg_by_card) {
    $ids = array_map(fn($r)=>(int)$r['id'], $cards_idle);
    if ($ids) {
      $place = implode(',', array_fill(0, count($ids), '?'));
      $rows = db_exec("
        SELECT c.id AS card_id, d.tg_nick
          FROM cards c
          LEFT JOIN drops d ON LOWER(d.name)=LOWER(c.drop_name)
         WHERE c.id IN ($place)
      ", $ids)->fetchAll();
      foreach ($rows as $r) if (!empty($r['tg_nick']) && !isset($tg_by_card[(int)$r['card_id']])) $tg_by_card[(int)$r['card_id']] = (string)$r['tg_nick'];
    }
  }
}

?>
<style>
  :root{
    --bg:#0b1017;
    --panel:#0e1520;
    --panel-2:#101a27;
    --border:#223149;
    --border-soft:#1b2940;
    --text:#e5efff;
    --muted:#9db1c9;
    --muted-2:#7f93ac;
    --warn:#ffcf6d; --warn-bg:#3a2a10;
    --danger:#ff8e8e; --danger-bg:#3b1717;
    --idle:#b3a3ff; --idle-bg:#2a2542;
    --shadow:0 6px 18px rgba(0,0,0,.30);
    --radius:14px;
  }
  .container{max-width:1160px}
  .grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(260px,1fr)); gap: 14px; align-items: stretch; }
  .tile {
    display:grid; gap:10px; padding:14px; border:1px solid var(--border);
    border-radius: var(--radius); background: linear-gradient(180deg, var(--panel), var(--panel-2));
    box-shadow: var(--shadow); text-decoration:none; color: inherit;
    transition: transform .06s ease, box-shadow .12s ease, border-color .12s ease, background .2s ease;
  }
  .tile:hover { transform: translateY(-1px); box-shadow: 0 10px 24px rgba(0,0,0,.35); border-color:#2c3e5d; }
  .tile .t-title { font-weight: 700; font-size: 16px; letter-spacing:.2px; }
  .tile .t-sub { color: var(--muted); font-size: 12px; }
  .tile .t-amt { font-variant-numeric: tabular-nums; font-weight:700; font-size: 15px;}
  .tile.warn { border-color:#4d3b18; background: linear-gradient(180deg, var(--panel), var(--warn-bg)); }
  .tile.danger { border-color:#613131; background: linear-gradient(180deg, var(--panel), var(--danger-bg)); }
  .tile.idle { border-color:#3a3266; background: linear-gradient(180deg, var(--panel), var(--idle-bg)); }
  .muted { color:var(--muted); }
  .h2 { display:flex; align-items:center; gap:10px; margin: 18px 0 10px; font-size:18px; font-weight:800; letter-spacing:.2px; }
  .pill { display:inline-block; padding:3px 8px; border-radius:999px; font-variant-numeric: tabular-nums; font-size:12px; border:1px solid #333; }
  .pill.warn { background:var(--warn-bg); border-color:#6b5a28; color:var(--warn); }
  .pill.danger { background:var(--danger-bg); border-color:#703030; color:var(--danger); }
  .note { font-size:12px; color:var(--muted-2); }
  .list { display:flex; flex-direction:column; gap:10px; }
  .row { display:flex; justify-content:space-between; gap:10px; align-items:center; padding:12px 14px; border:1px solid var(--border); border-radius:var(--radius); background: linear-gradient(180deg, var(--panel), var(--panel-2)); }
  .row .left { display:flex; flex-direction:column; gap:4px; }
  .row .right { text-align:right; }
  .split { display:grid; grid-template-columns: 1.1fr .9fr; gap: 32px; align-items:start; }
  @media (max-width: 960px){ .split{ grid-template-columns: 1fr; gap: 18px; } }
  .tag { display:inline-block; padding:4px 8px; border-radius:6px; font-size:12px; border:1px solid var(--border); color:#c8d0de; }
  .tag.small { font-size:11px; padding:2px 6px; }
</style>

<div class="container">
  <h1 style="margin:10px 0 6px;">Напоминалка</h1>
  <p class="muted" style="margin:0 0 18px;">Быстрые действия по картам и командам. Клик по карточке — мгновенный переход.</p>

  <!-- Notifications: Teams with low USD balance (как на teams.php через team_balance_calc) -->
  <section aria-labelledby="h-notify">
    <div class="h2" id="h-notify">Уведомления</div>
    <?php if (!$teams_low): ?>
      <div class="note">Нет команд с низким балансом.</div>
    <?php else: ?>
      <div class="list">
        <?php foreach ($teams_low as $t):
          $bal = (float)$t['balance_calc'];
          $cls = ($bal < 200 ? 'danger' : 'warn');
        ?>
        <div class="row">
          <div class="left">
            <div><b><?= h($t['name']) ?></b></div>
            <div class="note">Низкий баланс команды</div>
          </div>
          <div class="right">
            <span class="pill <?= $cls ?>"><?= fmt_usd($bal) ?></span><br>
            <a href="/admin/teams.php#team-<?= (int)$t['id'] ?>"" class="note">Открыть баланс →</a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <div style="height:8px"></div>

  <div class="split">
    <section aria-labelledby="h-lowbal">
      <div class="h2" id="h-lowbal">Карты с балансом &lt; 10 000 грн <span class="tag small"><?= count($cards_low_balance) ?></span></div>
      <?php if (!$cards_low_balance): ?>
        <div class="note">Все карты выше порога.</div>
      <?php else: ?>
        <div class="grid">
          <?php foreach ($cards_low_balance as $c):
            $last4 = mask_pan_last4($c['pan_last4'] ?? '');
            $cls = ((float)$c['balance_uah'] < 0) ? 'danger' : 'warn';
          ?>
            <a href="/admin/payments.php"
               class="tile <?= $cls ?>"
               onclick="return goPayments(<?= (int)$c['id'] ?>, <?= (int)($c['team_id'] ?? 0) ?>)"
               title="Открыть оплату по карте #<?= (int)$c['id'] ?>">
              <div class="t-title"><?= h($last4) ?></div>
              <div class="t-sub"><?= h($c['team_name'] ?? '—') ?> <span class="muted">→</span> <?= h($c['buyer_name'] ?? '—') ?></div>
              <div class="t-amt"><?= fmt_uah($c['balance_uah']) ?></div>
              <div class="t-sub">Клик — перейти в Оплаты/Пополнения</div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section aria-labelledby="h-limit">
      <div class="h2" id="h-limit">Карты с остатком лимита &lt; 20 000 грн <span class="tag small"><?= count($cards_low_limit) ?></span></div>
      <?php if (!$cards_low_limit): ?>
        <div class="note">Все остатки выше порога.</div>
      <?php else: ?>
        <div class="grid">
          <?php foreach ($cards_low_limit as $c):
            $last4 = mask_pan_last4($c['pan_last4'] ?? '');
            $left  = (float)($c['limit_left_uah'] ?? 0);
          ?>
            <a href="/admin/payments.php"
               class="tile warn"
               onclick="return goPayments(<?= (int)$c['id'] ?>, <?= (int)($c['team_id'] ?? 0) ?>)"
               title="Открыть оплату по карте #<?= (int)$c['id'] ?>">
              <div class="t-title"><?= h($last4) ?></div>
              <div class="t-sub"><?= h($c['team_name'] ?? '—') ?> <span class="muted">→</span> <?= h($c['buyer_name'] ?? '—') ?></div>
              <div class="t-amt"><?= fmt_uah($left) ?></div>
              <div class="t-sub">Остаток от лимита</div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <div style="height:18px"></div>

  <section aria-labelledby="h-idle">
    <div class="h2" id="h-idle">Карты без операций &gt; 24 часа <span class="tag small"><?= count($cards_idle) ?></span></div>
    <?php if (!$cards_idle): ?>
      <div class="note">Все карты активны.</div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($cards_idle as $c):
          $last4 = mask_pan_last4($c['pan_last4'] ?? '');
          $nick  = $tg_by_card[(int)$c['id']] ?? null;
          $tgurl = tg_link_from_nick($nick);
        ?>
          <?php if ($tgurl): ?>
            <a href="<?= h($tgurl) ?>" target="_blank" rel="noopener" class="tile idle" title="Открыть чат работника в Telegram">
              <div class="t-title"><?= h($last4) ?></div>
              <div class="t-sub"><?= h($c['team_name'] ?? '—') ?> <span class="muted">→</span> <?= h($c['buyer_name'] ?? '—') ?></div>
              <div class="t-amt"><?= $c['last_op_at'] ? ('посл. операция: '.fmt_dt($c['last_op_at'])) : 'Операций не было' ?></div>
              <div class="t-sub">Клик — открыть чат работника <?= h($nick) ?></div>
            </a>
          <?php else: ?>
            <a href="/admin/drops.php" class="tile" title="Открыть список работников (нет TG для карты)">
              <div class="t-title"><?= h($last4) ?></div>
              <div class="t-sub"><?= h($c['team_name'] ?? '—') ?> <span class="muted">→</span> <?= h($c['buyer_name'] ?? '—') ?></div>
              <div class="t-amt"><?= $c['last_op_at'] ? ('посл. операция: '.fmt_dt($c['last_op_at'])) : 'Операций не было' ?></div>
              <div class="t-sub">TG ник не найден — открыть список работников</div>
            </a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <div style="height:12px"></div>
</div>

<script>
  function goPayments(cardId, teamId){
    try{
      localStorage.setItem('payments.cardId', String(cardId));
      if (teamId) localStorage.setItem('payments.teamId', String(teamId));
    } catch(e){}
    window.location.href = '/admin/payments.php';
    return false;
  }
</script>

<?php include __DIR__.'/_layout_footer.php'; ?>
