// MEGuard – small helpers (no external dependencies)
document.addEventListener('DOMContentLoaded', () => {
  // snippets to copy into config.php
  document.querySelectorAll('.copybtn').forEach(b => {
    b.addEventListener('click', () => {
      const pre = b.parentNode.querySelector('pre');
      const done = () => { const o = b.textContent; b.textContent = b.dataset.copied || 'OK'; setTimeout(() => { b.textContent = o; }, 1500); };
      if (navigator.clipboard) navigator.clipboard.writeText(pre.textContent).then(done, () => {});
      else { const r = document.createRange(); r.selectNodeContents(pre); const s = getSelection(); s.removeAllRanges(); s.addRange(r); document.execCommand('copy'); done(); }
    });
  });

  // Tools drop-down menu: opens/closes on click or tap, closes when clicking elsewhere
  document.querySelectorAll('.dd').forEach(dd => {
    const b = dd.querySelector('.dd-btn');
    b.addEventListener('click', e => {
      e.stopPropagation();
      const open = !dd.classList.contains('open');
      document.querySelectorAll('.dd.open').forEach(x => x.classList.remove('open'));
      dd.classList.toggle('open', open);
      b.setAttribute('aria-expanded', open);
    });
  });
  document.addEventListener('click', () => document.querySelectorAll('.dd.open').forEach(x => { x.classList.remove('open'); x.querySelector('.dd-btn').setAttribute('aria-expanded', 'false'); }));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.dd.open').forEach(x => x.classList.remove('open')); });

  // bar heights in the chart
  document.querySelectorAll('[data-h]').forEach(el => { el.style.height = el.dataset.h + '%'; });
  document.querySelectorAll('[data-p]').forEach(el => { el.style.setProperty('--p', Math.min(100, Math.max(0, +el.dataset.p))); });
  document.querySelectorAll('[data-w]').forEach(el => { el.style.width = Math.min(100, Math.max(0, +el.dataset.w)) + '%'; });

  // clickable rows
  document.querySelectorAll('tr[data-href]').forEach(tr => {
    tr.addEventListener('click', e => {
      if (e.target.closest('a,button,form,input')) return;
      location.href = tr.dataset.href;
    });
  });

  // confirm before deleting
  document.querySelectorAll('[data-confirm]').forEach(b => {
    b.addEventListener('click', e => { if (!confirm(b.dataset.confirm)) e.preventDefault(); });
  });

  // long operations: disable the button and change its label
  document.querySelectorAll('form[data-busy]').forEach(f => {
    f.addEventListener('submit', () => {
      const b = f.querySelector('button');
      if (b) { b.disabled = true; b.textContent = f.dataset.busy; }
    });
  });

  // country filter presets
  document.querySelectorAll('[data-geo-set]').forEach(b => {
    b.addEventListener('click', () => {
      const set = new Set(b.dataset.geoSet ? b.dataset.geoSet.split(',') : []);
      document.querySelectorAll('#geolist input[type=checkbox]').forEach(c => { c.checked = set.has(c.value); });
    });
  });

  // vissza link
  document.querySelectorAll('[data-back]').forEach(a => {
    a.addEventListener('click', e => { if (history.length > 1) { e.preventDefault(); history.back(); } });
  });

  // quick filter in lists
  document.querySelectorAll('input[data-filter]').forEach(inp => {
    const box = document.querySelector(inp.dataset.filter);
    const sel = inp.dataset.item || 'tbody tr';
    inp.addEventListener('input', () => {
      const q = inp.value.toLowerCase();
      box.querySelectorAll(sel).forEach(el => {
        const txt = (el.tagName === 'FORM')
          ? Array.from(el.querySelectorAll('input,textarea')).map(i => i.value).join(' ')
          : el.textContent;
        el.style.display = txt.toLowerCase().includes(q) ? '' : 'none';
      });
    });
  });
});
