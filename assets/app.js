document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('table thead th').forEach((th,i)=>{
    th.addEventListener('click',()=>{
      const table=th.closest('table'); const tbody=table.tBodies[0]; const rows=[...tbody.querySelectorAll('tr')];
      const numeric=th.classList.contains('num');
      const dir = th.dataset.dir = th.dataset.dir==='asc' ? 'desc' : 'asc';
      rows.sort((a,b)=>{
        const ta=a.children[i].innerText.trim(); const tb=b.children[i].innerText.trim();
        const va=numeric? parseFloat(ta.replace(/[^0-9.-]+/g,'')) : ta.toLowerCase();
        const vb=numeric? parseFloat(tb.replace(/[^0-9.-]+/g,'')) : tb.toLowerCase();
        if(va<vb) return dir==='asc'?-1:1; if(va>vb) return dir==='asc'?1:-1; return 0;
      });
      rows.forEach(r=>tbody.appendChild(r));
    });
  });
  const filt=document.querySelector('select[name="filter_buyer_id"]');
  if(filt){ filt.addEventListener('change',()=>{
    const u=new URL(location.href); if(filt.value){ u.searchParams.set('buyer_id',filt.value); } else { u.searchParams.delete('buyer_id'); }
    location.href=u.toString();
  });}
});

document.addEventListener('change', (e)=>{
  const sel = e.target;
  if (sel && (sel.matches('select[name="new_status"]') || sel.matches('select[name="buyer_id"]'))) {
    const form = sel.closest('form');
    if (!form) return;
    let action = sel.name === 'new_status' ? 'set_status' : 'assign_buyer';
    let hidden = form.querySelector('input[name="action"]');
    if (!hidden) { hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'action'; form.appendChild(hidden); }
    hidden.value = action;
    form.submit();
  }
});
