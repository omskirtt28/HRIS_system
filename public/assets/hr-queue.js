'use strict';
// HR Request Queue: row selection, bulk bar, confirmation dialogs and the preview drawer.
// The page works without this file: every button posts a normal form and every link opens the full page.
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const picks = () => $$('.hq-pick');
  const checked = () => picks().filter(p => p.checked);
  const bar = $('[data-bulkbar]'), all = $('#hq-all');
  const bulkDialog = $('#hq-bulk-dialog'), quickDialog = $('#hq-quick-dialog'), drawer = $('#hq-drawer');
  const plural = (n, w) => n + ' ' + w + (n === 1 ? '' : 's');

  function refresh() {
    const rows = checked();
    if (bar) { bar.hidden = rows.length === 0; $('[data-bulk-count]', bar).textContent = rows.length + ' selected'; }
    if (all) { all.checked = rows.length > 0 && rows.length === picks().length; all.indeterminate = rows.length > 0 && rows.length < picks().length; }
  }
  all?.addEventListener('change', () => { picks().forEach(p => { p.checked = all.checked; }); refresh(); });
  document.addEventListener('change', e => { if (e.target.classList?.contains('hq-pick')) refresh(); });
  $('[data-bulk-clear]')?.addEventListener('click', () => { picks().forEach(p => { p.checked = false; }); refresh(); });

  const tally = rows => {
    const by = {};
    rows.forEach(r => { const n = r.dataset.typeName; by[n] = (by[n] || 0) + 1; });
    return Object.entries(by).map(([n, c]) => n + ' × ' + c).join(', ');
  };

  $$('[data-bulk-open]').forEach(btn => btn.addEventListener('click', () => {
    const action = btn.dataset.bulkOpen, picked = checked().map(p => p.closest('tr'));
    if (!picked.length || !bulkDialog) return;
    const verb = action === 'SERVED' ? 'Served' : 'Cancelled';
    // Only Pending/In Progress ATM, ID and Uniform requests are processed; the server decides, this is a preview.
    const ok = picked.filter(r => r.dataset.bulk === '1' && ['PENDING_HR', 'IN_PROGRESS'].includes(r.dataset.status));
    const skip = picked.filter(r => !ok.includes(r));
    $('[name=bulk_action]', bulkDialog).value = action;
    $('#hq-bulk-title').textContent = 'Mark ' + plural(picked.length, 'request') + ' as ' + verb + '?';
    $('[data-bulk-summary]', bulkDialog).textContent = ok.length ? plural(ok.length, 'request') + ' will be processed: ' + tally(ok) + '.' : 'None of the selected requests can be processed here.';
    const note = $('[data-bulk-skip]', bulkDialog);
    note.hidden = skip.length === 0;
    note.textContent = skip.length ? plural(skip.length, 'request') + ' will be skipped (' + tally(skip) + '). Only ATM, ID and Uniform requests that are Pending or In Progress can be processed in bulk.' : '';
    $('[data-bulk-confirm]', bulkDialog).disabled = ok.length === 0;
    bulkDialog.showModal();
  }));

  // Served / Cancel on a single request ask first. In Progress posts straight away.
  document.addEventListener('click', e => {
    const btn = e.target.closest('.hq-quick [data-confirm]');
    if (!btn || !quickDialog) return;
    e.preventDefault();
    const form = btn.closest('form'), f = $('#hq-quick-form');
    $('[name=ticket_id]', f).value = $('[name=ticket_id]', form).value;
    $('[name=version]', f).value = $('[name=version]', form).value;
    $('[name=do]', f).value = btn.value;
    $('#hq-quick-title').textContent = (btn.value === 'SERVED' ? 'Mark as Served' : 'Cancel this request') + '?';
    $('[data-quick-summary]', f).textContent = form.dataset.type + ' ' + form.dataset.ticket + '.';
    quickDialog.showModal();
  });

  document.addEventListener('click', e => {
    const close = e.target.closest('[data-dialog-close]');
    if (close) close.closest('dialog')?.close();
    // Click on the dimmed backdrop closes the dialog or drawer.
    if (e.target instanceof HTMLDialogElement) {
      const r = e.target.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) e.target.close();
    }
  });

  // Preview drawer. Falls back to the full page when the fragment cannot be loaded.
  let lastFocus = null;
  document.addEventListener('click', async e => {
    const link = e.target.closest('[data-preview]');
    if (!link || !drawer || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    lastFocus = link;
    const body = $('[data-drawer-body]', drawer);
    body.innerHTML = '<div class="hq-skel" aria-busy="true"><i></i><i></i><i></i><i></i></div>';
    if (!drawer.open) drawer.showModal();
    const keep = new URLSearchParams(location.search);
    ['page', 'id', 'p'].forEach(k => keep.delete(k));
    try {
      const res = await fetch(link.dataset.preview + (keep.toString() ? '&' + keep : ''), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
      if (!res.ok) throw new Error(String(res.status));
      body.innerHTML = await res.text();
      $('button', body)?.focus();
    } catch (_) {
      body.innerHTML = '<div class="hq-skel"><p>This preview could not be loaded.</p><a class="btn" href="' + link.getAttribute('href') + '">Open the full request</a><button type="button" class="btn" data-dialog-close>Close</button></div>';
    }
  });
  drawer?.addEventListener('close', () => { lastFocus?.focus(); });

  // Never send a bulk form with nothing selected (Enter key in the dialog).
  $('#hq-bulk-form')?.addEventListener('submit', e => { if (!checked().length) e.preventDefault(); });
  refresh();
})();
