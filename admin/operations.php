<?php
require_once __DIR__.'/../lib/auth.php';
$title='Операции'; $active='operations';
require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/helpers.php';

auth_require(); auth_require_admin();

$rows = db_exec("
  SELECT p.*, c.*, 
         b.name AS buyer_name, 
         t.name AS team_name
    FROM payments p
    LEFT JOIN cards  c ON c.id=p.card_id
    LEFT JOIN buyers b ON b.id=c.buyer_id
    LEFT JOIN teams  t ON t.id=b.team_id
   ORDER BY p.id DESC
   LIMIT 500
")->fetchAll();

/* Фолбэки, если вдруг нет в helpers (безопасно) */
if (!function_exists('card_last4_from_row')) {
  function card_last4_from_row(array $row): string {
    foreach (['pan_last4','number_last4','last4','card_number','pan','number'] as $k) {
      if (!empty($row[$k])) { $d=preg_replace('~\D~','',(string)$row[$k]); if($d!=='') return substr($d,-4); }
    }
    return isset($row['id']) ? substr(str_pad((string)$row['id'],4,'0',STR_PAD_LEFT),-4) : '????';
  }
}
if (!function_exists('mask_pan_last4')) {
  function mask_pan_last4(string $l4): string { $l4=preg_replace('~\D~','',$l4); $l4=substr($l4,-4); return '**** **** **** '.($l4?:'????'); }
}

require __DIR__.'/_layout.php';
?>

<style>
/* ---------- Базовая таблица ---------- */
.card { background:#0b1220; border:1px solid rgba(148,163,184,.2); border-radius:12px; }
.card .card-body { padding:14px 16px; }
.table { width:100%; border-collapse:collapse; }
.table thead th { text-align:left; border-bottom:1px solid rgba(148,163,184,.25); padding:10px; }
.table tbody td { border-bottom:1px dashed rgba(148,163,184,.18); padding:10px; vertical-align:top; }
.table .num { text-align:right; }
.muted { color:#94a3b8; }
.table-wrap { overflow:auto; -webkit-overflow-scrolling:touch; }

/* ---------- Чипы типа операции ---------- */
.chip {
  display:inline-block; font-size:12px; line-height:1; padding:4px 8px; border-radius:999px;
  border:1px solid #273043; background:#111827; color:#cbd5e1; text-transform:lowercase;
}
.chip.debit { color:#ef4444; border-color:#7f1d1d; background:#2a0c0c; }
.chip.topup { color:#22c55e; border-color:#14532d; background:#052e1a; }
.chip.hold  { color:#eab308; border-color:#4d3b0a; background:#221a08; }

/* ---------- Панель фильтров ---------- */
.filters { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom:10px; }
.filters label { display:block; }
.filters input, .filters select { min-width:220px; }

/* ---------- Мобильная карточная раскладка ---------- */
:root { --tap-size: 44px; }

@media (max-width: 900px){
  .table-ops { display:block; border:0; min-width:0 !important; }
  .table-ops thead { display:none; }
  .table-ops tbody { display:grid; gap:12px; }
  .table-ops tbody tr{
    display:grid; grid-template-columns:1fr;
    background:#0f172a; border:1px solid #222; border-radius:12px; padding:12px;
    box-shadow:0 6px 18px rgba(0,0,0,.25);
  }
  .table-ops tbody tr > td{
    display:grid; grid-template-columns:auto 1fr;
    gap:8px; padding:6px 0; border:0; vertical-align:top;
  }
  .table-ops tbody tr > td::before{
    content:''; color:#8b93a7; font-size:12px; line-height:1.2; padding-top:4px; white-space:nowrap;
  }
  .table-ops tbody tr > td:nth-child(1)::before { content:"ID"; }
  .table-ops tbody tr > td:nth-child(2)::before { content:"Дата"; }
  .table-ops tbody tr > td:nth-child(3)::before { content:"Карта"; }
  .table-ops tbody tr > td:nth-child(4)::before { content:"Команда → Баер"; }
  .table-ops tbody tr > td:nth-child(5)::before { content:"Тип"; }
  .table-ops tbody tr > td:nth-child(6)::before { content:"Сумма UAH"; }
  .table-ops tbody tr > td:nth-child(7)::before { content:"Заметка"; }

  /* поля фильтров — в колонку */
  .filters { flex-direction:column; align-items:stretch; }
  .filters input, .filters select, .filters .btn { width:100%; min-height:var(--tap-size); font-size:15px; }
}

/* Средние ширины — липкий заголовок */
@media (min-width: 901px) and (max-width: 1200px){
  .table-ops thead th { position:sticky; top:0; background:#0b1220; z-index:1; }
}
</style>

<div class="card">
  <div class="card-body" style="padding-bottom:8px;">
    <form class="filters" onsubmit="return false" autocomplete="off">
      <label>Тип операции
        <select id="opFilterType" onchange="filterOps()">
          <option value="all">Все</option>
          <option value="topup">Пополнение</option>
          <option value="debit">Списание</option>
          <option value="hold">Холд</option>
          <option value="void">Void</option>
        </select>
      </label>
      <label style="flex:1">Поиск (ID / last4 / Команда / Баер / Заметка)
        <input id="opFilterSearch" placeholder="Начните ввод..." oninput="filterOps()">
      </label>
      <button class="btn" type="button" onclick="clearOpFilters()">Сбросить</button>
    </form>
  </div>

  <div class="table-wrap">
    <table class="table table-ops">
      <thead>
        <tr>
          <th class="num">ID</th>
          <th>Дата</th>
          <th>Карта</th>
          <th>Команда → Баер</th>
          <th>Тип</th>
          <th class="num">Сумма UAH</th>
          <th>Заметка</th>
        </tr>
      </thead>
      <tbody id="opsBody">
        <?php foreach($rows as $r):
          $last4 = card_last4_from_row($r);
          $op    = strtolower($r['type'] ?? ($r['kind'] ?? ($r['operation_type'] ?? ($r['op'] ?? ($r['action'] ?? '')))));
          $op    = $op ?: '';
          $chipClass = in_array($op, ['debit','topup','hold'], true) ? $op : '';
          $tb = trim((($r['team_name'] ?? '') . ' → ' . ($r['buyer_name'] ?? '')));
          $note = (string)($r['note'] ?? '');
        ?>
        <tr
          data-id="<?= (int)$r['id'] ?>"
          data-last4="<?= h($last4) ?>"
          data-type="<?= h($op) ?>"
          data-tb="<?= h($tb) ?>"
          data-note="<?= h($note) ?>"
        >
          <td class="num"><?= (int)$r['id'] ?></td>
          <td class="muted"><?= h($r['created_at'] ?? '') ?></td>
          <td class="td-mono"><?= mask_pan_last4($last4) ?></td>
          <td><?= h($tb) ?></td>
          <td><span class="chip <?= h($chipClass) ?>"><?= h($op) ?></span></td>
          <td class="num"><?= money_uah($r['amount_uah'] ?? 0) ?></td>
          <td><?= h($note) ?></td>
        </tr>
        <?php endforeach; if (empty($rows)): ?>
          <tr><td colspan="7" class="muted">Пока нет операций</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function clearOpFilters(){
  document.getElementById('opFilterType').value = 'all';
  document.getElementById('opFilterSearch').value = '';
  filterOps();
}
function filterOps(){
  const type = (document.getElementById('opFilterType').value || 'all').toLowerCase();
  const q = (document.getElementById('opFilterSearch').value || '').toLowerCase().trim();
  const rows = document.querySelectorAll('#opsBody tr');
  rows.forEach(tr=>{
    const op   = (tr.getAttribute('data-type')||'').toLowerCase();
    const id   = String(tr.getAttribute('data-id')||'').toLowerCase();
    const l4   = (tr.getAttribute('data-last4')||'').toLowerCase();
    const tb   = (tr.getAttribute('data-tb')||'').toLowerCase();
    const note = (tr.getAttribute('data-note')||'').toLowerCase();
    const okType = (type==='all') || (op===type) || (type==='void' && op==='void');
    const okText = (!q) || id.includes(q) || l4.includes(q) || tb.includes(q) || note.includes(q);
    tr.style.display = (okType && okText) ? '' : 'none';
  });
}
</script>

<?php include __DIR__.'/_layout_footer.php'; ?>
