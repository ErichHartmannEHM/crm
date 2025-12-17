// admin/scanner/scanner.js
async function apiPost(action, body) {
  const r = await fetch('api.php?action=' + encodeURIComponent(action), {
    method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body || {}), cache:'no-store'
  });
  const ct = r.headers.get('Content-Type') || ''; if (ct.includes('text/csv')) return r.text(); return r.json();
}
async function apiGet(action, params) {
  const qs = new URLSearchParams(params || {}); qs.append('_ts', Date.now());
  const url = 'api.php?action=' + encodeURIComponent(action) + '&' + qs.toString();
  const r = await fetch(url, {method:'GET', cache:'no-store'}); return r.json();
}

document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('importForm');
  if (form) form.addEventListener('submit', (e)=>{ e.preventDefault(); e.stopPropagation(); return false; });

  if (!document.getElementById('modal')) {
    document.body.insertAdjacentHTML('beforeend', `
<div id="modal" class="modal" style="display:none">
  <div class="modal-body">
    <div class="modal-head">
      <b>Історія заявки <span id="mh_id"></span></b>
      <button id="modalClose">×</button>
    </div>
    <div class="tablewrap"><table class="table">
      <thead><tr><th>Дата</th><th>Час</th><th>Статус</th><th>Коментар</th></tr></thead>
      <tbody id="histBody"></tbody>
    </table></div>
  </div>
</div>`);
  }

  document.querySelectorAll('.tablink').forEach(btn=>{
    btn.addEventListener('click', (e)=>{
      e.preventDefault(); e.stopPropagation();
      document.querySelectorAll('.tablink').forEach(b=>b.classList.remove('active'));
      document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
      btn.classList.add('active');
      const sec = document.getElementById('tab-' + btn.dataset.tab);
      if (sec) sec.classList.add('active');
    });
  });

  const close = document.getElementById('modalClose');
  if (close) close.onclick = ()=>{ const m=document.getElementById('modal'); if (m) m.style.display='none'; };

  const btnImport = document.getElementById('btnImport');
  if (btnImport) btnImport.addEventListener('click', async (e) => {
    e.preventDefault(); e.stopPropagation();
    const ids = (document.getElementById('ids') || {}).value || '';
    const wid = (document.getElementById('worker') || {}).value || '';
    const r = await apiPost('import', {ids, worker_id: wid === '' ? null : Number(wid)});
    const im = document.getElementById('importMsg'); if (im) im.textContent = `Добавлено: ${r.added || 0}, уже были: ${r.exists || 0}`;
    const ta = document.getElementById('ids'); if (ta) ta.value = '';
    reload();
  });

  const btnReload = document.getElementById('btnReload'); if (btnReload) btnReload.addEventListener('click', (e)=>{ e.preventDefault(); reload(); });
  const btnScanAll = document.getElementById('btnScanAll');
  if (btnScanAll) btnScanAll.addEventListener('click', async (e) => {
    e.preventDefault();
    const wid = (document.getElementById('fWorker') || {}).value || '';
    const r = await apiPost('scan_all', {worker_id: wid === '' ? null : Number(wid)});
    alert(`Проверено: ${r.checked || 0}\nОшибок: ${r.errors || 0}`);
    reload();
  });

  const btnMove = document.getElementById('btnMove');
  if (btnMove) btnMove.addEventListener('click', async (e) => {
    e.preventDefault();
    const ids = [...document.querySelectorAll('.sel:checked')].map(x => x.value);
    const wid = (document.getElementById('moveTo') || {}).value || '';
    if (!ids.length) { alert('Выберите заявки в таблице'); return; }
    const r = await apiPost('reassign', {ids, worker_id: wid === '' ? null : Number(wid)});
    const mm = document.getElementById('moveMsg'); if (mm) mm.textContent = `Перемещено: ${r.moved || 0}`;
    reload();
  });

  (async () => { await loadWorkers(); await reload(); await reloadProxies(); })();
});

async function loadWorkers() {
  const res = await apiGet('workers');
  const ws = Array.isArray(res) ? res : (res.items || []);
  const sel = document.getElementById('worker');
  const fWorker = document.getElementById('fWorker');
  const moveTo = document.getElementById('moveTo');
  const px_worker = document.getElementById('px_worker');
  [sel,fWorker,moveTo,px_worker].forEach(s => { if (s) s.innerHTML = ''; });
  if (sel) { const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='— не назначать —'; sel.appendChild(opt0); }
  if (fWorker) { const optAll = document.createElement('option'); optAll.value=''; optAll.textContent='— любой —'; fWorker.appendChild(optAll); }
  if (px_worker) { const optNone = document.createElement('option'); optNone.value=''; optNone.textContent='— без привязки —'; px_worker.appendChild(optNone); }
  ws.forEach(w => {
    if (sel){ sel.appendChild(new Option(w.name, w.id)); }
    if (fWorker){ fWorker.appendChild(new Option(w.name, w.id)); }
    if (moveTo){ moveTo.appendChild(new Option(w.name, w.id)); }
    if (px_worker){ px_worker.appendChild(new Option(w.name, w.id)); }
  });
}

async function reload() {
  const wid = (document.getElementById('fWorker') || {}).value ?? '';
  const st  = (document.getElementById('fStatus') || {}).value ?? '';
  const data = await apiGet('list', {worker_id: wid, status: st});
  const tbody = document.querySelector('#tbl tbody'); if (tbody) tbody.innerHTML = '';
  (data.items || []).forEach(row => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input type="checkbox" class="sel" value="${row.request_id}"></td>
      <td>
        <a href="https://privatbank.ua/refund/${encodeURIComponent(row.request_id)}" target="_blank">${row.request_id}</a>
        <button class="hist btn" data-id="${row.request_id}">історія</button>
      </td>
      <td>${row.worker_name || '<span class="text-muted">—</span>'}</td>
      <td>${row.last_status ? '<span class="label">'+row.last_status+'</span>' : '<span class="text-muted">—</span>'}</td>
      <td class="comment-cell" data-id="${row.request_id}"><span class="text-muted">загрузка…</span></td>
      <td>${row.last_dt || '<span class="text-muted">—</span>'}</td>
      <td><button data-id="${row.request_id}" class="scanOne btn">Проверить</button></td>
    `;
    tbody && tbody.appendChild(tr);
    // подтягиваем последний комментарий по заявке (асинхронно, чтобы не блочить перерисовку таблицы)
    const commentCell = tr.querySelector('.comment-cell');
    if (commentCell) { loadLastComment(row.request_id, commentCell); }
  });

  const cs = data.counters?.byStatus || [];
  const cw = data.counters?.byWorker || [];
  const elS = document.getElementById('cntStatus'); if (elS) elS.innerHTML = '<b>По статусам:</b> ' + (cs.map(x => `<span class="badge">${x.status || '—'}: ${x.cnt}</span>`).join(' ') || '<span class="text-muted">нет</span>');
  const elW = document.getElementById('cntWorker'); if (elW) elW.innerHTML = '<b>По сотрудникам:</b> ' + (cw.map(x => `<span class="badge">${x.name || '—'}: ${x.cnt}</span>`).join(' ') || '<span class="text-muted">нет</span>');

  const stSel = document.getElementById('fStatus'); if (stSel) {
    const seen = new Set([...stSel.options].map(o => o.value));
    cs.forEach(x => { if (!seen.has(x.status)) stSel.appendChild(new Option(x.status, x.status)); });
  }

  document.querySelectorAll('button.scanOne').forEach(btn => {
    btn.addEventListener('click', async (e) => { e.preventDefault(); btn.disabled = true; await apiPost('scan_one', {id: btn.dataset.id}); btn.disabled = false; reload(); });
  });

  document.querySelectorAll('button.hist').forEach(btn=>{
    btn.addEventListener('click', async (e)=>{
      e.preventDefault();
      const id = btn.dataset.id;
      const data = await apiGet('history', {id});
      const mh = document.getElementById('mh_id'); if (mh) mh.textContent = id;
      const hb = document.getElementById('histBody'); if (hb) hb.innerHTML='';
      (data.items || []).forEach(r=>{
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${r.d||''}</td><td>${r.t||''}</td><td>${r.status||''}</td><td>${r.comment||''}</td>`;
        hb && hb.appendChild(tr);
      });
      const m = document.getElementById('modal'); if (m) m.style.display='flex';
    });
  });

  const selAll = document.getElementById('selAll');
  if (selAll) { selAll.checked=false; selAll.onchange = () => { document.querySelectorAll('.sel').forEach(ch => ch.checked = selAll.checked); }; }

  const btnExport = document.getElementById('btnExport');
  if (btnExport) btnExport.href = 'api.php?action=export' + '&worker_id=' + encodeURIComponent(wid) + '&status=' + encodeURIComponent(st) + '&_ts=' + Date.now();
}



async function loadLastComment(requestId, cell) {
  try {
    const data = await apiGet('history', {id: requestId});
    const items = data.items || [];
    if (!items.length) {
      cell.innerHTML = '<span class="text-muted">—</span>';
      return;
    }
    const last = items[items.length - 1] || {};
    const rawComment = (last.comment || '').toString().trim();
    if (!rawComment) {
      cell.innerHTML = '<span class="text-muted">—</span>';
      return;
    }
    const short = rawComment.length > 140 ? rawComment.slice(0, 137) + '…' : rawComment;
    cell.textContent = short;
    cell.title = rawComment;
  } catch (e) {
    cell.innerHTML = '<span class="text-muted">ошибка</span>';
  }
}

async function reloadProxies() {
  const list = await apiGet('proxies_list');
  const tb = document.querySelector('#tblProxies tbody'); if (tb) tb.innerHTML='';
  (list || []).forEach(p=>{
    const tr=document.createElement('tr');
    tr.innerHTML = `
      <td>${p.id}</td>
      <td>${p.title}</td>
      <td><code>${p.proxy_url}</code></td>
      <td>${p.worker_name||'—'}</td>
      <td>${p.batch_limit}</td>
      <td>${p.refresh_wait_sec}</td>
      <td>${Number(p.active)?'Да':'Нет'}</td>
      <td>
        <button class="pxEdit btn" data-id="${p.id}">Ред.</button>
        <button class="pxRefresh btn" data-id="${p.id}">Refresh</button>
        <button class="pxTest btn" data-id="${p.id}">Test</button>
        <button class="pxDel btn" data-id="${p.id}">Удалить</button>
      </td>`;
    tb && tb.appendChild(tr);
  });

  document.querySelectorAll('.pxEdit').forEach(b=>b.onclick = ()=>{
    const id = Number(b.dataset.id);
    const p = (list || []).find(x=>Number(x.id)===id) || {};
    const setVal = (id, val) => { const el=document.getElementById(id); if (el) el.value = val; };
    setVal('px_id', p.id || '');
    setVal('px_title', p.title || '');
    setVal('px_proxy', p.proxy_url || '');
    setVal('px_refresh', p.refresh_url || '');
    setVal('px_worker', p.assigned_worker_id || '');
    setVal('px_limit', p.batch_limit || 10);
    setVal('px_wait', p.refresh_wait_sec || 20);
    setVal('px_active', Number(p.active||1));
  });

  document.querySelectorAll('.pxDel').forEach(b=>b.onclick = async (e)=>{
    e.preventDefault();
    if(!confirm('Удалить прокси?')) return;
    await apiPost('proxy_delete', {id:Number(b.dataset.id)}); reloadProxies();
  });

  document.querySelectorAll('.pxRefresh').forEach(b=>b.onclick = async (e)=>{
    e.preventDefault(); b.disabled = true;
    await apiPost('proxy_refresh', {id:Number(b.dataset.id)});
    b.disabled = false; alert('IP обновлён.');
  });

  document.querySelectorAll('.pxTest').forEach(b=>b.onclick = async (e)=>{
    e.preventDefault(); b.disabled = true;
    const r = await apiPost('proxy_test', {id:Number(b.dataset.id)});
    b.disabled = false; alert(r.ok ? ('IP: ' + r.ip) : ('Ошибка: ' + r.error));
  });
}
