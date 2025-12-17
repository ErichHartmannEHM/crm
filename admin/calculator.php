<?php
declare(strict_types=1);
$page = 'calculator'; // highlight in sidebar
require_once __DIR__ . '/../lib/partners.php';
require_once __DIR__ . '/../lib/telegram.php';
ensure_partners_schema();

// ----- Use data helpers from partners.php -----
$drops    = calc_fetch_drops();
$teams    = teams_all_for_calc();
$partners = partners_all(true);

$sel_drop_id = isset($_GET['drop_id']) ? (int)$_GET['drop_id'] : (count($drops) ? (int)$drops[0]['id'] : 0);
$sel_team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : null;
$amount      = isset($_GET['amount']) ? (float)$_GET['amount'] : 0.0;


// Add auth and AJAX endpoints
require_once __DIR__ . '/../lib/auth.php';
auth_require();

if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    $act = isset($_GET['ajax']) ? $_GET['ajax'] : $_POST['ajax'];
    header('Content-Type: application/json; charset=utf-8');

    try {
        if ($act === 'compute') {
            $drop_id = isset($_GET['drop_id']) ? (int)$_GET['drop_id'] : 0;
            $team_id = isset($_GET['team_id']) && $_GET['team_id'] !== '' ? (int)$_GET['team_id'] : null;
            $amount  = isset($_GET['amount']) ? (float)$_GET['amount'] : 0.0;

            // NEW: live preview by explicit partner_ids[]
            $partner_ids = [];
            if (isset($_GET['partner_ids'])) {
                $partner_ids = is_array($_GET['partner_ids'])
                    ? array_map('intval', $_GET['partner_ids'])
                    : [ (int)$_GET['partner_ids'] ];
                $partner_ids = array_values(array_unique(array_filter($partner_ids)));
            }
            if (!empty($partner_ids)) {
                $placeholders = implode(',', array_fill(0, count($partner_ids), '?'));
                $rows = calc_db_all(
                    "SELECT id, name, percent FROM partners WHERE is_active=1 AND id IN ($placeholders) ORDER BY name ASC",
                    $partner_ids
                );

                $partners_total = 0.0;
                $lines = [];
                foreach ($rows as $p) {
                    $pc  = isset($p['percent']) ? (float)$p['percent'] : 0.0;
                    $amt = round($amount * $pc / 100.0, 2);
                    $lines[] = [
                        'id'      => (int)($p['id'] ?? 0),
                        'name'    => (string)($p['name'] ?? ''),
                        'percent' => $pc,
                        'amount'  => $amt,
                    ];
                    $partners_total += $amt;
                }
                // Team partner (auto-added from selected team)
                $tp = team_partner_get($team_id);
                if ($tp) {
                    $tp_id = (int)$tp['id'];
                    if (!in_array($tp_id, $partner_ids, true)) {
                        $pc  = (float)$tp['percent'];
                        $amt = round($amount * $pc / 100.0, 2);
                        $lines[] = [
                            'id'      => $tp_id,
                            'name'    => (string)$tp['name'].' (команда)',
                            'percent' => $pc,
                            'amount'  => $amt,
                        ];
                        $partners_total += $amt;
                    }
                }

                $team_percent = team_percent_get($team_id);
                $team_amount  = round($amount * $team_percent / 100.0, 2);
                $worker_income = round($amount - $partners_total - $team_amount, 2);

                echo json_encode([
                    'partners'       => $lines,
                    'partners_total' => round($partners_total, 2),
                    'team_percent'   => $team_percent,
                    'team_amount'    => $team_amount,
                    'worker_income'  => $worker_income,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Default behavior (effective mapping from DB)
            $data = calculator_compute($drop_id, $team_id, $amount);
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'drop-partners') {
            $drop_id = isset($_GET['drop_id']) ? (int)$_GET['drop_id'] : 0;
            $ids = $drop_id ? drop_partner_ids($drop_id) : [];
            echo json_encode(['ids'=>$ids], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'save-mapping') {
            $drop_id = isset($_POST['drop_id']) ? (int)$_POST['drop_id'] : 0;
            $ids = [];
            if (isset($_POST['partner_ids'])) {
                $ids = is_array($_POST['partner_ids']) ? array_map('intval', $_POST['partner_ids']) : [ (int)$_POST['partner_ids'] ];
            }
            if ($drop_id > 0) {
                drop_partners_save($drop_id, $ids);
            }
            echo json_encode(['ok'=>true, 'saved'=>count($ids)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if ($act === 'payout') {
            // Send Telegram messages to partners with linked chat IDs.
            // Accepts JSON body: { partners: [ {id:int, amount:float}, ... ] }
            $raw = file_get_contents('php://input');
            $data = json_decode((string)$raw, true);
            $pairs = [];
            if (is_array($data) && isset($data['partners']) && is_array($data['partners'])) {
                foreach ($data['partners'] as $row) {
                    $pid = isset($row['id']) ? (int)$row['id'] : 0;
                    $amt = isset($row['amount']) ? (float)$row['amount'] : 0.0;
                    if ($pid > 0 && $amt > 0) {
                        if (!isset($pairs[$pid])) $pairs[$pid] = 0.0;
                        $pairs[$pid] += (float)$amt;
                    }
                }
            }
            // Fallback: support form-encoded partners[id]=amount style
            if (empty($pairs) && isset($_POST['partners']) && is_array($_POST['partners'])) {
                foreach ($_POST['partners'] as $pid => $amt) {
                    $pid = (int)$pid; $amt = (float)$amt;
                    if ($pid > 0 && $amt > 0) {
                        if (!isset($pairs[$pid])) $pairs[$pid] = 0.0;
                        $pairs[$pid] += (float)$amt;
                    }
                }
            }
            if (empty($pairs)) {
                echo json_encode(['ok'=>false,'error'=>'empty payload'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // Resolve partner chat column
            $chatCol = null;
            foreach (['tg_chat_id','chat_id','telegram_chat_id','tg_chat','telegram_chat'] as $c) {
                if (calc_column_exists('partners', $c)) { $chatCol = $c; break; }
            }
            if (!$chatCol) { echo json_encode(['ok'=>false,'error'=>'chat column not found'], JSON_UNESCAPED_UNICODE); exit; }

            // Fetch chats
            $ids = array_keys($pairs);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = calc_db_all("SELECT id, name, $chatCol AS chat FROM partners WHERE id IN ($placeholders)", $ids);
            $byId = [];
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $pid = (int)($r['id'] ?? 0);
                    $chat = isset($r['chat']) ? (string)$r['chat'] : '';
                    $name = isset($r['name']) ? (string)$r['name'] : '';
                    if ($pid > 0) { $byId[$pid] = ['chat'=>$chat, 'name'=>$name]; }
                }
            }

            $sent = 0; $missing = []; $errors = []; $sent_ids = [];
            foreach ($pairs as $pid => $amt) {
                $amt = round((float)$amt, 2);
                $row = $byId[$pid] ?? null;
                if (!$row || empty($row['chat'])) { $missing[] = $pid; continue; }
                $chat = (string)$row['chat'];
                // Compose message
                $money = number_format($amt, 2, '.', '');
                $text = "Привет, твоя выплата: ₴ <b>{$money}</b>";
                $err = null;
                $ok = function_exists('telegram_send') ? telegram_send($chat, $text, $err) : false;
                if ($ok) { $sent++; $sent_ids[] = $pid; }
                else { $errors[] = ['id'=>$pid, 'error'=>(string)($err??'failed to send')]; }
            }
            echo json_encode(['ok'=>true, 'sent'=>$sent, 'total'=>count($pairs), 'sent_ids'=>$sent_ids, 'missing'=>$missing, 'errors'=>$errors], JSON_UNESCAPED_UNICODE);
            exit;
        }
echo json_encode(['ok'=>false, 'error'=>'unknown action'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
<?php include __DIR__ . '/_layout.php'; ?>

<style>
/* ====== Calculator Redesign (scoped) ====== */
:root {
  --calc-bg: #0f1722;
  --calc-card: #121a26;
  --calc-muted: #8aa0b2;
  --calc-accent: #4c8bf5;      /* primary accent */
  --calc-green: #22c55e;
  --calc-yellow: #f59e0b;
  --calc-red: #ef4444;
  --calc-border: rgba(255,255,255,.06);
}
.calx * { box-sizing: border-box; }
.calx .card { background: var(--calc-card); border: 1px solid var(--calc-border); border-radius: 14px; }
.calx .card-body { padding: 16px 18px; }
.calx .title { font-size: 22px; font-weight: 700; margin-bottom: 12px; }
.calx .muted { color: var(--calc-muted); }

/* Inputs row */
.calx .grid {
  display: grid;
  grid-template-columns: 1.4fr 1fr 1fr auto;
  gap: 12px;
}
@media (max-width: 1100px){ .calx .grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 640px){ .calx .grid { grid-template-columns: 1fr; } }

.calx .form-label { font-size: 12px; color: var(--calc-muted); margin-bottom: 6px; }
.calx .form-control, .calx .form-select {
  background: #0e1622; color: #e9eef5; border: 1px solid var(--calc-border); border-radius: 10px;
  padding: 10px 12px; height: 42px;
}
.calx .btn-primary { background: var(--calc-accent); border: none; height: 42px; border-radius: 10px; }
.calx .btn-outline { background: transparent; border: 1px solid var(--calc-border); color: #e9eef5; border-radius: 10px; }

/* Summary strip */
.calx .summary {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;
}
@media (max-width: 1100px){ .calx .summary { grid-template-columns: repeat(2, 1fr);} }
@media (max-width: 640px){ .calx .summary { grid-template-columns: 1fr;} }
.calx .summary .tile {
  background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
  border: 1px solid var(--calc-border); border-radius: 12px; padding: 12px 14px;
}
.calx .summary .k { font-size: 12px; color: var(--calc-muted); }
.calx .summary .v { font-size: 22px; font-weight: 800; margin-top: 4px; }
.calx .summary .v.ok { color: var(--calc-green); }
.calx .summary .v.warn { color: var(--calc-yellow); }

/* Breakdown */
.calx .table { width: 100%; margin: 0; border-collapse: collapse; }
.calx .table th, .calx .table td { padding: 10px 8px; border-bottom: 1px dashed var(--calc-border); }
.calx .table th { font-weight: 600; color: var(--calc-muted); }
.calx .table .num { text-align: right; }

/* Bars */
.calx .bars { display: grid; grid-template-columns: 1fr; gap: 6px; }
.calx .bar { position: relative; height: 10px; border-radius: 999px; background: #0e1622; overflow: hidden; }
.calx .bar .fill { position:absolute; left:0; top:0; bottom:0; width:0; background: var(--calc-accent); transition: width .35s ease; }
.calx .bar.team .fill { background: #8b5cf6; }   /* violet */
.calx .bar.worker .fill { background: var(--calc-green); }

/* Right panel partners */
.calx .side { position: sticky; top: 20px; }
.calx .partner-line { display:flex; align-items:center; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed var(--calc-border); }
.calx .partner-line:last-child { border-bottom: 0; }
.calx .badge { font-size: 12px; padding: 4px 8px; border-radius: 999px; background: rgba(255,255,255,.06); color:#cfe0ff; }
.calx .actions { display:flex; gap:8px; margin-top: 10px; }

/* Mini helper row under inputs */
.calx .helper-row { display:flex; align-items:center; gap:10px; margin-top:8px; color: var(--calc-muted); }
.calx .helper-row .dot { width:8px; height:8px; border-radius:999px; background: var(--calc-accent); display:inline-block; }
.calx .helper-row .dot.team { background:#8b5cf6; }
.calx .helper-row .dot.worker { background: var(--calc-green); }

/* --- Modern switch (toggle) --- */
.calx .switch { position: relative; display: inline-block; width: 42px; height: 22px; }
.calx .switch input { opacity: 0; width: 0; height: 0; }
.calx .switch .slider { position: absolute; cursor: pointer; top:0; left:0; right:0; bottom:0;
  background:#122033; transition: .2s; border-radius: 999px; border:1px solid var(--calc-border); }
.calx .switch .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; top: 2px;
  background: #cfe0ff; transition: .2s; border-radius:50%; }
.calx .switch input:checked + .slider { background: var(--calc-accent); border-color: transparent; }
.calx .switch input:checked + .slider:before { transform: translateX(20px); background: #0b1320; }

/* partner tiles */
.calx .summary .tile.partner-tile .k { display:flex; align-items:center; gap:6px; }
.calx .summary .tile .pct-badge { font-size: 11px; line-height: 1; padding: 3px 6px; border-radius: 999px;
  background: rgba(255,255,255,.08); color:#cfe0ff; border:1px solid var(--calc-border); }


/* --- History sidebar --- */
.calx .history-list { display: grid; gap: 10px; margin-top: 8px; }
.calx .hist-item { border: 1px solid var(--calc-border); border-radius: 12px; padding: 10px 12px; background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02)); }
.calx .hist-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.calx .hist-amount { font-weight: 800; font-size: 16px; }
.calx .hist-meta { color: var(--calc-muted); font-size: 12px; }
.calx .hist-sums { display: grid; grid-template-columns: 1fr; gap: 4px; margin-top: 6px; font-size: 13px; }
.calx .hist-actions { display:flex; gap:6px; }
.calx button.btn-mini { border: 1px solid var(--calc-border); background: transparent; border-radius: 8px; padding: 4px 8px; line-height: 1; font-size: 12px; color: #e9eef5; }
.calx details.hist-details { margin-top: 8px; }
.calx details.hist-details summary { cursor: pointer; color: var(--calc-muted); font-size: 12px; }
.calx .hist-list-inner { margin-top: 6px; border-top: 1px dashed var(--calc-border); padding-top: 6px; }
.calx .hist-row { display:flex; align-items:center; justify-content:space-between; padding: 4px 0; font-size: 13px; }
.calx .hist-row .num { text-align:right; }


/* Payout buttons — compact, not full-width */
.calx .hist-actions .btn-pay{
  height: 30px;
  padding: 6px 12px;
  font-weight: 700;
  border-radius: 8px;
  line-height: 1;
}
.calx .btn-pay-all{
  height: 36px;
  padding: 8px 14px;
  font-weight: 700;
  border-radius: 10px;
}
@media (max-width: 560px){
  .calx .hist-actions .btn-pay{ height: 28px; padding: 5px 10px; font-size: 12px; }
  .calx .btn-pay-all{ height: 34px; padding: 8px 12px; font-size: 13px; }
}

</style>

<div class="content calx">

  <!-- ===== Inputs + Summary ===== -->
  <div class="card mb-3">
    <div class="card-body">
      <div class="title">Калькулятор доходности</div>
      <form id="calcForm" onsubmit="return false">
        <div class="grid">
          <div>
            <label class="form-label">Работник</label>
            <input list="tgNickList" class="form-control" id="tgNickInput" placeholder="ник без @ (быстрый поиск)">
            <datalist id="tgNickList">
              <?php foreach ($drops as $d): $nick = trim((string)($d['tg_nick'] ?? '')); if ($nick): ?>
                <option value="<?=htmlspecialchars($nick)?>"></option>
              <?php endif; endforeach; ?>
            </datalist>
            <select id="dropSelect" class="form-select mt-2">
              <?php foreach ($drops as $d):
                    $nick = trim((string)($d['tg_nick'] ?? ''));
                    $name = trim((string)($d['name'] ?? ('ID '.$d['id'])));
                    $label = $name . ($nick ? ' @'.$nick : '');
                    $id = (int)$d['id'];
                    $sel = $id === $sel_drop_id ? 'selected' : '';
              ?>
                <option value="<?=$id?>" <?=$sel?>><?=htmlspecialchars($label)?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="form-label">Команда</label>
            <select id="teamSelect" class="form-select">
              <option value="">Без команды</option>
              <?php foreach ($teams as $t): $id=(int)$t['id']; $name=trim((string)$t['name']); $sel=($sel_team_id && $id===$sel_team_id)?'selected':''; ?>
                <option value="<?=$id?>" <?=$sel?>><?=htmlspecialchars($name)?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="form-label">Сумма возврата, грн</label>
            <input type="number" step="0.01" min="0" id="amountInput" class="form-control" value="<?=htmlspecialchars((string)$amount)?>">
          </div>

          <div style="display:flex; gap:8px; align-items:flex-end;">
            <button id="calcBtn" class="btn btn-primary" type="button">Посчитать</button>
            <button id="clearBtn" class="btn btn-outline" type="button">Сброс</button>
          </div>
        </div>

        <div class="helper-row">
          <span class="dot"></span><span>партнёры</span>
          <span class="dot team"></span><span>команда</span>
          <span class="dot worker"></span><span>доход работника</span>
        </div>
      </form>
    </div>
  </div>

  <div class="row">
    <!-- ===== Main (Summary + Breakdown) ===== -->
    <div class="col-lg-8">
      <div class="card mb-3">
        <div class="card-body">
          <div class="summary" id="summaryStrip">
            <div class="tile">
              <div class="k">Сумма возврата</div>
              <div class="v" id="sAmount">0,00</div>
            </div>
            <div class="tile">
              <div class="k">Итого партнёры <span id="partnersPct" class="pct-badge" style="display:none"></span></div>
              <div class="v" id="sPartners">0,00</div>
              <div class="bar"><div class="fill" id="barPartners" style="width:0%"></div></div>
            </div>
            <div class="tile">
              <div class="k">Команда <span id="teamPct" class="pct-badge" style="display:none"></span></div>
              <div class="v" id="sTeam">0,00</div>
              <div class="bar team"><div class="fill" id="barTeam" style="width:0%"></div></div>
            </div>
            <div class="tile">
              <div class="k">Доход работника</div>
              <div class="v ok" id="sWorker">0,00</div>
              <div class="bar worker"><div class="fill" id="barWorker" style="width:0%"></div></div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <div class="title" style="font-size:16px;">Детализация</div>
          <div id="calcResult" class="table-wrap">
            <div class="muted">Введите сумму и выберите параметры, чтобы увидеть результат.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ===== Side: Partners per worker ===== -->
    <div class="col-lg-4">
      <div class="card side">
        <div class="card-body">
          <div class="title" style="font-size:16px; display:flex; align-items:center; justify-content:space-between;">
            <span>Партнёры по работнику</span>
            <button id="saveMappingBtn" class="btn btn-outline btn-sm" style="height:32px;">Сохранить</button>
          </div>
          <div class="muted" style="margin-top:-6px;">По умолчанию используются EHM и Parfumer (если привязки не заданы).</div>
          <div class="actions">
            <button id="presetDefault" class="btn btn-outline btn-sm" type="button">EHM + Parfumer</button>
            <button id="presetAll" class="btn btn-outline btn-sm" type="button">Выбрать всех</button>
            <button id="presetNone" class="btn btn-outline btn-sm" type="button">Очистить</button>
          </div>

          <div class="mt-2">
            <input type="text" id="partnerSearch" class="form-control" placeholder="Поиск партнёра...">
          </div>

          <div id="partnerChecks" class="mt-2">
            <?php foreach ($partners as $p): ?>
              <div class="partner-line" data-name="<?=htmlspecialchars(strtolower($p['name']))?>">
                <label class="switch">
                  <input class="partner-chk" type="checkbox" value="<?=$p['id']?>">
                  <span class="slider"></span>
                </label>
                <span class="form-check-label" style="flex:1; margin-left:10px;"><?=$p['name']?></span>
                <span class="badge"><?=number_format((float)$p['percent'], 2, ',', ' ')?>%</span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card mt-3 history-card">
        <div class="card-body">
          <div class="title" style="font-size:16px; display:flex; align-items:center; justify-content:space-between;">
            <span>История просчётов</span>
            <div class="actions">
              <button id="historyPayAll" class="btn btn-primary btn-pay-all" type="button">Выплатить всё</button>
              <button id="historyClear" class="btn btn-outline btn-sm" type="button">Очистить</button>
            </div>
          </div>
          <div id="historyList" class="history-list muted">Пока пусто.</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const dropSel   = document.getElementById('dropSelect');
  const teamSel   = document.getElementById('teamSelect');
  const amountInp = document.getElementById('amountInput');
  const tgInput   = document.getElementById('tgNickInput');
  const clearBtn  = document.getElementById('clearBtn');
  const resultDiv = document.getElementById('calcResult');
  const saveBtn   = document.getElementById('saveMappingBtn');
  const partnerBox= document.getElementById('partnerChecks');
  const calcBtn   = document.getElementById('calcBtn');
  const historyList = document.getElementById('historyList');
  const historyClear = document.getElementById('historyClear');
  const historyPayAll = document.getElementById('historyPayAll');
  let lastData = null;
  async function sendPayout(partners){
    try{
      const clean = (Array.isArray(partners)? partners : []).map(p => ({
        id: Number(p.id||p.partner_id||0),
        amount: Number(p.amount||0)
      })).filter(x => x.id>0 && x.amount>0);
      if (!clean.length){ alert('Нет сумм для выплаты.'); return; }
      const url = new URL(window.location.href); url.search=''; url.hash='';
      url.searchParams.set('ajax','payout');
      const res = await fetch(url.toString(), {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({partners: clean})
      });
      const j = await res.json();
      if (j && j.ok){
        const miss = (j.missing||[]).length;
        alert('Отправлено сообщений: '+(j.sent||0)+ (miss? ('; без ChatID: '+miss):''));
      } else {
        alert('Ошибка: '+(j && j.error ? j.error : 'не удалось отправить'));
      }
    } catch (e){
      alert('Ошибка сети: '+e);
    }
  }

  async function payoutHistoryAt(index){
    const items = loadHist();
    const it = items[index];
    if (!it || !Array.isArray(it.partners) || !it.partners.length){
      alert('Эта запись без партнёров'); return;
    }
    await sendPayout(it.partners);
  }

  async function payoutAllHistory(){
    const items = loadHist();
    if (!items.length){ alert('История пустая'); return; }
    const totals = new Map();
    items.forEach(it => {
      (it.partners||[]).forEach(p => {
        const id = Number(p.id||0);
        const amt= Number(p.amount||0);
        if (id>0 && amt>0){ totals.set(id, (totals.get(id)||0) + amt); }
      });
    });
    const partners = Array.from(totals.entries()).map(([id, amount]) => ({id, amount}));
    if (!partners.length){ alert('В истории нет сумм для партнеров'); return; }
    await sendPayout(partners);
  }



  const sAmount  = document.getElementById('sAmount');
  const sPartners= document.getElementById('sPartners');
  const sTeam    = document.getElementById('sTeam');
  const sWorker  = document.getElementById('sWorker');
  const barP     = document.getElementById('barPartners');
  const barT     = document.getElementById('barTeam');
  const barW     = document.getElementById('barWorker');

  function fmt(n){ return new Intl.NumberFormat('ru-RU',{minimumFractionDigits:2, maximumFractionDigits:2}).format(n); }
  function animateValue(el, to){
    const from = parseFloat((el.dataset.v||'0').replace(',','.'))||0;
    const start = performance.now();
    const dur = 250;
    function step(ts){
      const k = Math.min(1,(ts-start)/dur);
      const v = from + (to-from)*k;
      el.textContent = fmt(v);
      el.dataset.v = v;
      if (k<1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  async function compute(){
    const drop_id = dropSel.value || 0;
    const team_id = teamSel.value || '';
    const amount  = parseFloat(amountInp.value||'0') || 0;

    const url = new URL(window.location.href);
    url.search = ''; url.hash = '';
    url.searchParams.set('ajax','compute');
    url.searchParams.set('drop_id', drop_id);
    if (team_id!=='') url.searchParams.set('team_id', team_id);
    url.searchParams.set('amount', amount.toString());
    // NEW: pass current partner checkboxes as partner_ids[] for live preview
    partnerBox.querySelectorAll('input.partner-chk:checked').forEach(ch => {
      url.searchParams.append('partner_ids[]', ch.value);
    });

    const res = await fetch(url.toString(), {credentials:'same-origin'});
    const data = await res.json();

    // snapshot for history
    lastData = {
      ts: Date.now(),
      drop: { id: Number(drop_id||0), label: (dropSel.selectedOptions[0]?.textContent||'').trim() },
      team: { id: (team_id!==''? Number(team_id): null), label: (team_id!=='' ? (teamSel.selectedOptions[0]?.textContent||'').trim() : 'Без команды') },
      amount: Number(amount||0),
      partners: Array.isArray(data.partners) ? data.partners.map(row => ({
        id: Number(row.id||0),
        name: String(row.name||''),
        percent: Number(row.percent||0),
        amount: Number(row.amount||0)
      })) : [],
      partners_total: Number(data.partners_total||0),
      team_percent: Number(data.team_percent||0),
      team_amount: Number(data.team_amount||0),
      worker_income: Number(data.worker_income||0)
    };

    // summary
    animateValue(sAmount, amount);
    animateValue(sPartners, Number(data.partners_total||0));
    animateValue(sTeam, Number(data.team_amount||0));
    animateValue(sWorker, Number(data.worker_income||0));

    const p = amount>0 ? (Number(data.partners_total||0)/amount*100) : 0;
    const t = amount>0 ? (Number(data.team_amount||0)/amount*100) : 0;
    const w = amount>0 ? (Number(data.worker_income||0)/amount*100) : 0;
    barP.style.width = p+'%'; barT.style.width = t+'%'; barW.style.width = w+'%';
    // show percent badges
    const teamPct = document.getElementById('teamPct');
    const partPct = document.getElementById('partnersPct');
    if (teamPct){ teamPct.style.display='inline-block'; teamPct.textContent = (t? t.toFixed(2):'0.00') + '%'; }
    if (partPct){ partPct.style.display='inline-block'; partPct.textContent = (p? p.toFixed(2):'0.00') + '%'; }

    // Render partner tiles (each partner separately)
    const summary = document.getElementById('summaryStrip');
    // remove old partner tiles
    summary.querySelectorAll('.partner-tile').forEach(el => el.remove());
    if (Array.isArray(data.partners)) {
      data.partners.forEach(row => {
        const percent = amount>0 ? (Number(row.amount||0)/amount*100) : 0;
        const tile = document.createElement('div');
        tile.className = 'tile partner-tile';
        tile.innerHTML = `
          <div class="k">Партнёр <span class="pct-badge">${(row.percent||0).toFixed(2)}%</span></div>
          <div class="v">${fmt(Number(row.amount||0))}</div>
          <div class="bar"><div class="fill" style="width:${percent}%"></div></div>`;
        const title = tile.querySelector('.k');
        title.insertAdjacentText('afterbegin', String(row.name||''));
        summary.appendChild(tile);
      });
    }


    // table
    let html = '';
    if (data && data.partners && data.partners.length){
      html += '<div class="table-responsive"><table class="table">';
      html += '<thead><tr><th>Партнёр</th><th class="num">%</th><th class="num">Сумма, грн</th></tr></thead><tbody>';
      data.partners.forEach(row => {
        html += `<tr><td>${escapeHtml(row.name)}</td><td class="num">${fmt(row.percent)}</td><td class="num">${fmt(row.amount)}</td></tr>`;
      });
      html += `</tbody><tfoot>
        <tr><th>Итого партнёры</th><th></th><th class="num">${fmt(data.partners_total)}</th></tr>
        <tr><th>Команда</th><th class="num">${fmt(data.team_percent)}</th><th class="num">${fmt(data.team_amount)}</th></tr>
        <tr><th>Доход работника</th><th></th><th class="num">${fmt(data.worker_income)}</th></tr>
      </tfoot></table></div>`;

      const totalPercent = data.partners.reduce((s,r)=>s+Number(r.percent||0),0) + Number(data.team_percent||0);
      if (totalPercent > 100.00001){
        html += `<div class="alert alert-warning mt-2">Внимание: суммарный процент (${fmt(totalPercent)}%) превышает 100%.</div>`;
      }
    } else {
      html = '<div class="muted">Для выбранного работника не найдено активных партнёров.</div>';
    }
    resultDiv.innerHTML = html;
  }

  function escapeHtml(s){ return (s??'').toString().replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }

  async function loadMapping(){
    const drop_id = dropSel.value || 0;
    const url = new URL(window.location.href);
    url.search = ''; url.hash = '';
    url.searchParams.set('ajax','drop-partners');
    url.searchParams.set('drop_id', drop_id);
    const res = await fetch(url.toString(), {credentials:'same-origin'});
    const data = await res.json();
    const ids = (data && data.ids) ? data.ids.map(x=>String(x)) : [];
    partnerBox.querySelectorAll('input.partner-chk').forEach(chk => {
      chk.checked = ids.includes(chk.value);
    });
  }

  async function saveMapping(){
    const drop_id = dropSel.value || 0;
    const form = new FormData();
    form.set('ajax','save-mapping');
    form.set('drop_id', drop_id);
    partnerBox.querySelectorAll('input.partner-chk:checked').forEach((chk)=>{ form.append('partner_ids[]', chk.value); });
    const url = new URL(window.location.href);
    url.search = ''; url.hash = '';
    const res = await fetch(url.toString(), {method:'POST', body:form, credentials:'same-origin'});
    await res.json();
  }

  // Presets
  document.getElementById('presetDefault').addEventListener('click', () => {
    partnerBox.querySelectorAll('input.partner-chk').forEach(chk => {
      const name = chk.closest('.partner-line').querySelector('.form-check-label').textContent.trim().toLowerCase();
      chk.checked = (name === 'ehm' || name === 'parfumer');
    });
    compute();
  });
  document.getElementById('presetAll').addEventListener('click', () => {
    partnerBox.querySelectorAll('input.partner-chk').forEach(chk => chk.checked = true); compute();
  });
  document.getElementById('presetNone').addEventListener('click', () => {
    partnerBox.querySelectorAll('input.partner-chk').forEach(chk => chk.checked = false); compute();
  });

  // Partner search filter
  const pSearch = document.getElementById('partnerSearch');
  pSearch.addEventListener('input', () => {
    const q = pSearch.value.trim().toLowerCase();
    partnerBox.querySelectorAll('.partner-line').forEach(row => {
      const name = row.getAttribute('data-name') || '';
      row.style.display = name.includes(q) ? '' : 'none';
    });
  });

  // fast select by tg nick
  tgInput.addEventListener('input', () => {
    const v = tgInput.value.trim().toLowerCase();
    if (!v) return;
    const opts = [...dropSel.options];
    const match = opts.find(o => o.text.toLowerCase().includes('@'+v) || o.text.toLowerCase().endsWith(' '+v));
    if (match) { dropSel.value = match.value; dropSel.dispatchEvent(new Event('change')); }
  });

  // clear
  clearBtn.addEventListener('click', () => {
    amountInp.value = '';
    teamSel.value = '';
    compute();
  });
  // NEW: recompute on partner toggles
  partnerBox.addEventListener('change', (e) => {
    if (e.target && e.target.matches('input.partner-chk')) { compute(); }
  });


  dropSel.addEventListener('change', () => { loadMapping(); compute(); });
  teamSel.addEventListener('change', compute);
  amountInp.addEventListener('input', compute);
  saveBtn.addEventListener('click', async ()=>{ await saveMapping(); await compute(); });


  // ---- History helpers ----
  const HISTORY_KEY = 'calc.history.v1';

  function loadHist(){ try { return JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]'); } catch(e){ return []; } }
  function saveHist(items){ localStorage.setItem(HISTORY_KEY, JSON.stringify(items)); }

  function renderHistory(){
    const items = loadHist();
    if (!historyList) return;
    if (!items.length){
      historyList.innerHTML = '<div class="muted">Пока пусто.</div>';
      return;
    }
    historyList.innerHTML = items.map((it, i) => {
      const amt = fmt(Number(it.amount||0));
      const pTot = Number(it.partners_total||0);
      const tAmt = Number(it.team_amount||0);
      const wAmt = Number(it.worker_income||0);
      const pPct = (Number(it.amount||0) > 0) ? (pTot / Number(it.amount||0) * 100) : 0;
      const tPct = Number(it.team_percent||0);
      const when = new Date(Number(it.ts||Date.now())).toLocaleString('ru-RU');
      const head = `
        <div class="hist-head">
          <div>
            <div class="hist-amount">₴ ${amt}</div>
            <div class="hist-meta">${escapeHtml(it.drop?.label||'')}${it.team?.label? ' · '+escapeHtml(it.team.label):''} · ${when}</div>
          </div>
          <div class="hist-actions">
            <button class="btn btn-primary btn-pay" data-act="payout" data-i="${i}">Выплатить</button>
          
            <button class="btn-mini" data-act="apply" data-i="${i}" title="Применить">↩</button>
            <button class="btn-mini" data-act="remove" data-i="${i}" title="Удалить">×</button>
          </div>
        </div>`;
      const sums = `
        <div class="hist-sums">
          <div>Партнёры: <b>₴ ${fmt(pTot)}</b> (${pPct.toFixed(2)}%)</div>
          <div>Команда: <b>₴ ${fmt(tAmt)}</b> (${tPct.toFixed(2)}%)</div>
          <div>Работник: <b>₴ ${fmt(wAmt)}</b></div>
        </div>`;
      const details = Array.isArray(it.partners) && it.partners.length ? `
        <details class="hist-details">
          <summary>Детализация партнёров</summary>
          <div class="hist-list-inner">
            ${it.partners.map(p => `
              <div class="hist-row">
                <div>${escapeHtml(p.name||'')}</div>
                <div class="num">${(Number(p.percent||0)).toFixed(2)}% · ₴ ${fmt(Number(p.amount||0))}</div>
              </div>
            `).join('')}
          </div>
        </details>
      ` : '';
      return `<div class="hist-item">${head}${sums}${details}</div>`;
    }).join('');
  }

  function addHistory(){
    if (!lastData || Number(lastData.amount||0) <= 0) return;
    const items = loadHist();
    // keep concise copy + partner ids for quick apply
    const entry = JSON.parse(JSON.stringify(lastData));
    entry.partner_ids = Array.isArray(lastData.partners) ? lastData.partners.map(p => Number(p.id||0)).filter(Boolean) : [];
    items.unshift(entry);
    if (items.length > 50) items.length = 50; // cap
    saveHist(items);
    renderHistory();
  }

  async function applyHistoryAt(index){
    const items = loadHist();
    const it = items[index];
    if (!it) return;
    // set selections
    if (it.drop && it.drop.id != null) { dropSel.value = String(it.drop.id); }
    await loadMapping();
    if (Array.isArray(it.partner_ids)){
      const ids = new Set(it.partner_ids.map(x=>String(x)));
      partnerBox.querySelectorAll('input.partner-chk').forEach(chk => chk.checked = ids.has(chk.value));
    }
    if (it.team) { teamSel.value = (it.team.id != null ? String(it.team.id) : ''); }
    amountInp.value = String(Number(it.amount||0));
    compute();
  }

  // events
  if (calcBtn) calcBtn.addEventListener('click', async () => { await compute(); addHistory(); });
  if (historyClear) historyClear.addEventListener('click', () => { saveHist([]); renderHistory(); });
  if (historyPayAll) historyPayAll.addEventListener('click', async () => { await payoutAllHistory(); });

  if (historyList) historyList.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const i = Number(btn.getAttribute('data-i')||'-1');
    const act = btn.getAttribute('data-act');
    if (act === 'remove'){
      const items = loadHist();
      if (i>=0 && i<items.length){ items.splice(i,1); saveHist(items); renderHistory(); }
    } else if (act === 'apply'){
      await applyHistoryAt(i);
    } else if (act === 'payout'){
      await payoutHistoryAt(i);
    }
  });
  // init
  renderHistory();
  loadMapping().then(compute);
})();
</script>