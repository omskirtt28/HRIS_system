// Select visible records only. Server permissions and stage checks remain authoritative.
document.querySelectorAll('form[data-cutoff-select], form[data-hr-bulk-review]').forEach(function (form) {
  if (form.hasAttribute('data-hr-friendly-review')) return;
  const items = Array.from(form.querySelectorAll('[data-cutoff-item]'));
  const selectAll = form.querySelector('[data-cutoff-select-all]');
  const count = form.querySelector('[data-cutoff-selection-count]');
  const submit = form.querySelector('[data-cutoff-submit]');
  const hrReview = form.hasAttribute('data-hr-bulk-review');
  function refresh() {
    if (hrReview) items.forEach(function (item) {
      const reviewed = item.closest('tr').querySelector('[data-ticket-reviewed]');
      item.disabled = !reviewed.checked;
      if (!reviewed.checked) item.checked = false;
    });
    const available = items.filter(function (item) { return !item.disabled; });
    const selected = available.filter(function (item) { return item.checked; }).length;
    if (count) count.textContent = selected + ' selected';
    if (submit) submit.disabled = selected === 0;
    if (selectAll) {
      selectAll.disabled = available.length === 0;
      selectAll.checked = available.length > 0 && selected === available.length;
      selectAll.indeterminate = selected > 0 && selected < available.length;
    }
  }
  form.addEventListener('change', refresh);
  if (selectAll) selectAll.addEventListener('change', function () {
    items.forEach(function (item) { if (!item.disabled) item.checked = selectAll.checked; });
    refresh();
  });
  const reviewedButton = form.querySelector('[data-select-reviewed]');
  if (reviewedButton) reviewedButton.addEventListener('click', function () {
    refresh();items.forEach(function (item) { item.checked = !item.disabled; });refresh();
  });
  refresh();
});

// HR review uses server-authorized request fragments and a local reviewed state.
// Mark reviewed is preparation only; the existing POST handler verifies each ticket.
document.querySelectorAll('[data-hr-review-workspace]').forEach(function (workspace) {
  const form = workspace.querySelector('[data-hr-friendly-review]');
  const rows = Array.from(workspace.querySelectorAll('[data-hr-row]'));
  const panel = workspace.querySelector('[data-hr-detail]');
  const body = panel.querySelector('[data-hr-detail-body]');
  const layout = workspace.querySelector('.hr-review-layout');
  const all = workspace.querySelector('[data-hr-select-all]');
  const selectionControl = workspace.querySelector('[data-hr-selection-control]');
  const waiting = workspace.querySelector('[data-hr-waiting]');
  const help = workspace.querySelector('[data-hr-review-help]');
  const bulk = form.querySelector('[data-hr-bulk-bar]');
  const count = form.querySelector('[data-cutoff-selection-count]');
  const submit = form.querySelector('[data-cutoff-submit]');
  const empty = workspace.querySelector('[data-hr-empty]');
  let tab = workspace.querySelector('[data-hr-tab]') ? 'new' : 'sent';
  let controller = null;
  let requestSequence = 0;
  let opener = null;
  function refresh() {
    const reviewed = rows.filter(row => row.dataset.reviewState === 'reviewed');
    rows.forEach(function (row) {
      row.hidden = row.dataset.reviewState !== tab;
      const selected = row.querySelector('[data-cutoff-item]');
      if (selected) {
        selected.disabled = row.dataset.reviewState !== 'reviewed';
        if (selected.disabled) selected.checked = false;
        row.querySelector('[data-hr-row-selection]').hidden = selected.disabled;
        row.querySelector('[data-hr-row-start]').hidden = !selected.disabled;
        row.classList.toggle('is-selected', selected.checked);
      }
    });
    const selected = reviewed.filter(row => row.querySelector('[data-cutoff-item]').checked).length;
    const visibleReviewed = reviewed.filter(row => !row.hidden);
    if (all) {
      const selectedVisible = visibleReviewed.filter(row => row.querySelector('[data-cutoff-item]').checked).length;
      all.checked = visibleReviewed.length > 0 && selectedVisible === visibleReviewed.length;
      all.indeterminate = selectedVisible > 0 && selectedVisible < visibleReviewed.length;
    }
    if (selectionControl) selectionControl.hidden = tab !== 'reviewed' || visibleReviewed.length === 0;
    if (waiting) waiting.hidden = tab === 'reviewed' && visibleReviewed.length > 0;
    if (help) help.textContent = tab === 'reviewed'
      ? 'Select the checked requests, then Send for approval. Reviewed marks last until you leave this page.'
      : 'Click Review, check the details, then Mark reviewed. Selection appears in Reviewed.';
    if (count) count.textContent = selected + ' selected · reviewed';
    if (bulk) bulk.hidden = selected === 0;
    if (submit) { submit.disabled = selected === 0; submit.querySelector('[data-cutoff-submit-label]').textContent = 'Send for approval (' + selected + ')'; }
    const newCount = workspace.querySelector('[data-hr-new-count]');
    const reviewedCount = workspace.querySelector('[data-hr-reviewed-count]');
    if (newCount) newCount.textContent = rows.filter(row => row.dataset.reviewState === 'new').length;
    if (reviewedCount) reviewedCount.textContent = reviewed.length;
    workspace.querySelectorAll('[data-hr-tab]').forEach(function (button) {
      const active = button.dataset.hrTab === tab;
      button.classList.toggle('is-active', active); button.setAttribute('aria-pressed', String(active));
    });
    empty.hidden = rows.some(row => !row.hidden);
    if (!empty.hidden && rows.length) empty.textContent = tab === 'reviewed' ? 'Open a request and mark it reviewed first.' : 'All requests on this page have been checked. Open Reviewed to select and send them.';
    syncDetail();
  }
  function syncDetail() {
    const loaded = body.querySelector('[data-hr-loaded-request]');
    const mark = body.querySelector('[data-hr-mark-reviewed]');
    if (!loaded || !mark || mark.disabled) return;
    const row = rows.find(row => row.dataset.hrRow === loaded.dataset.hrLoadedRequest);
    if (!row) return;
    const reviewed = row.dataset.reviewState === 'reviewed';
    mark.hidden = reviewed;
    body.querySelector('[data-hr-reviewed-note]').hidden = !reviewed;
    const select = body.querySelector('[data-hr-select-request]');
    select.hidden = !reviewed;
    select.disabled = reviewed && row.querySelector('[data-cutoff-item]').checked;
  }
  function close() {
    requestSequence++;
    if (controller) controller.abort();
    panel.hidden = true; layout.classList.remove('has-detail');
    if (opener && !opener.closest('[hidden]')) opener.focus();
    else workspace.querySelector('[data-hr-tab].is-active')?.focus();
  }
  workspace.addEventListener('click', async function (event) {
    const open = event.target.closest('[data-hr-open]');
    if (open) {
      event.preventDefault(); opener = open;
      if (controller) controller.abort();
      controller = new AbortController();
      const sequence = ++requestSequence;
      const endpoint = new URL(open.href, window.location.href); endpoint.searchParams.set('hr_panel', '1');
      panel.hidden = false; layout.classList.add('has-detail');
      body.textContent = 'Loading request…'; body.setAttribute('aria-busy', 'true');
      try {
        const response = await fetch(endpoint, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
        if (!response.ok || response.redirected) throw new Error('Unable to load request');
        const markup = await response.text();
        if (sequence !== requestSequence) return;
        const template = document.createElement('template'); template.innerHTML = markup;
        const loaded = template.content.querySelector('[data-hr-loaded-request]');
        if (!loaded || loaded.dataset.hrLoadedRequest !== open.dataset.hrOpen) throw new Error('Invalid request response');
        body.replaceChildren(template.content);
        const row = rows.find(row => row.dataset.hrRow === open.dataset.hrOpen);
        const mark = body.querySelector('[data-hr-mark-reviewed]');
        if (mark && (!row || Number(loaded.dataset.hrLoadedStep) !== Number(row.querySelector('[name^="hr_steps"]').value))) {
          mark.disabled = true; mark.textContent = 'Request changed · refresh page';
        }
        syncDetail();
        panel.querySelector('[data-hr-close]').focus();
      } catch (error) {
        if (error.name === 'AbortError' || sequence !== requestSequence) return;
        const text = document.createElement('p'); text.className = 'panel-body small'; text.setAttribute('role', 'alert');
        text.textContent = 'Could not load this request. Refresh the page or open the full request.';
        const fallback = document.createElement('a'); fallback.href = open.href; fallback.className = 'btn sm'; fallback.textContent = 'Open full request';
        body.replaceChildren(text, fallback);
      } finally { if (sequence === requestSequence) body.removeAttribute('aria-busy'); }
      return;
    }
    const tabButton = event.target.closest('[data-hr-tab]');
    if (tabButton) { tab = tabButton.dataset.hrTab; refresh(); return; }
    if (event.target.closest('[data-hr-close]')) { close(); return; }
    const mark = event.target.closest('[data-hr-mark-reviewed]');
    if (mark && !mark.disabled) {
      const row = rows.find(row => row.dataset.hrRow === mark.dataset.hrMarkReviewed);
      if (!row || row.dataset.reviewState === 'sent') return;
      row.dataset.reviewState = 'reviewed'; row.querySelector('[data-ticket-reviewed]').disabled = false;
      const badge = row.querySelector('[data-hr-row-status]'); badge.textContent = 'Reviewed'; badge.className = 'badge green hr-review-state';
      row.querySelector('[data-hr-open-label]').textContent = 'Open';
      tab = 'reviewed'; refresh(); body.querySelector('[data-hr-select-request]').focus(); return;
    }
    const selectRequest = event.target.closest('[data-hr-select-request]');
    if (selectRequest && !selectRequest.disabled) {
      const row = rows.find(row => row.dataset.hrRow === selectRequest.dataset.hrSelectRequest);
      if (!row || row.dataset.reviewState !== 'reviewed') return;
      row.querySelector('[data-cutoff-item]').checked = true;
      tab = 'reviewed'; refresh(); return;
    }
    if (event.target.closest('[data-hr-return-toggle]')) {
      const returned = body.querySelector('[data-hr-return-form]');
      returned.hidden = !returned.hidden;
      if (!returned.hidden) returned.querySelector('textarea').focus();
    }
  });
  workspace.addEventListener('change', function (event) {
    if (event.target === all) rows.forEach(function (row) {
      const item = row.querySelector('[data-cutoff-item]');
      if (item && !row.hidden && !item.disabled) item.checked = all.checked;
    });
    refresh();
  });
  workspace.addEventListener('keydown', event => { if (event.key === 'Escape' && !panel.hidden) close(); });
  refresh();
});

document.querySelectorAll('[data-cutoff-generate-ready]').forEach(function (form) {
  const check = form.querySelector('[name="filing_closed"]');
  const button = form.querySelector('[data-cutoff-generate-button]');
  const refresh = () => { button.disabled = form.dataset.cutoffGenerateReady !== '1' || !check.checked; };
  check.addEventListener('change', refresh); refresh();
});
document.addEventListener('click', function (event) {
  const link = event.target.closest('[data-open-cutoff-details]');
  if (!link) return;
  const details = document.getElementById(link.getAttribute('href').slice(1));
  if (details instanceof HTMLDetailsElement) details.open = true;
});
if (window.location.hash) {
  const target = document.getElementById(window.location.hash.slice(1));
  if (target instanceof HTMLDetailsElement) target.open = true;
}

document.addEventListener('submit', function (event) {
  const form = event.target;
  if (form instanceof HTMLFormElement && form.dataset.payrollConfirm && !window.confirm(form.dataset.payrollConfirm)) event.preventDefault();
});

document.querySelectorAll('form[data-payroll-upload]').forEach(function (form) {
  const input = form.querySelector('input[type="file"]');
  const selectedCount = form.querySelector('input[name="selected_file_count"]');
  const selection = form.querySelector('[data-upload-selection]');
  const error = form.querySelector('[data-upload-error]');
  if (!input || !selectedCount || !selection || !error) return;

  function checkFiles() {
    const files = Array.from(input.files || []);
    const total = files.reduce(function (sum, file) { return sum + file.size; }, 0);
    const maxFiles = Number(form.dataset.maxFiles);
    let message = '';
    selectedCount.value = String(files.length);
    selection.textContent = files.length
      ? files.length + ' file(s) selected (' + (total / 1024 / 1024).toFixed(1) + ' MB). All files will use the selected cutoff.'
      : 'No files selected.';
    if (files.length > maxFiles) {
      message = 'Choose up to ' + maxFiles + ' files at a time.';
    } else if (files.some(function (file) { return !/\.(pdf|csv|xlsx)$/i.test(file.name); })) {
      message = 'Choose PDF, CSV or XLSX files only.';
    } else if (files.some(function (file) { return file.size > Number(form.dataset.maxFileBytes); })) {
      message = 'A selected file is too large. Maximum: ' + form.dataset.maxFileLabel + ' per file.';
    } else if (total > Number(form.dataset.maxTotalBytes)) {
      message = 'The files are too large together. Keep the total below ' + form.dataset.maxTotalLabel + ' or select fewer files.';
    }
    input.setCustomValidity(message);
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
    error.textContent = message;
    error.hidden = !message;
    return !message;
  }

  input.addEventListener('change', checkFiles);
  form.addEventListener('submit', function (event) {
    if (event.defaultPrevented) return;
    if (!checkFiles() || !input.checkValidity()) {
      event.preventDefault();
      input.reportValidity();
    }
  });
  checkFiles();
});

// Cutoff and day filters apply immediately; native GET navigation keeps deep links usable.
document.querySelectorAll('form[data-attendance-auto-filter]').forEach(function (form) {
  form.addEventListener('change', function (event) {
    if (!(event.target instanceof HTMLSelectElement)) return;
    form.setAttribute('aria-busy', 'true');
    form.requestSubmit();
  });
});

(() => {
  const form = document.getElementById('payrollRequestForm');
  const block = form && form.querySelector('[data-ta-context-url]');
  if (!form || !block) return;
  const type = document.getElementById('phase3aRequestType');
  const date = document.getElementById('phase3aAffectedDate');
  const cutoff = document.getElementById('phase3aCutoff');
  const submit = form.querySelector('button[type="submit"]');
  const message = block.querySelector('[data-ta-message]');
  const retry = block.querySelector('[data-ta-retry]');
  const fields = Array.from(block.querySelectorAll('[data-ta-field]'));
  const labels = {time_in: 'Time In', lunch_out: 'Break Out', lunch_in: 'Break In', time_out: 'Time Out'};
  let context = null;
  try { context = JSON.parse(block.dataset.taInitial || 'null'); } catch (_) {}
  let loading = false;
  let failed = false;
  let sequence = 0;
  let controller = null;
  let lastDate = date.value;
  const drafts = new Map();
  const isTA = () => ['TA', 'PTA'].includes(type.selectedOptions[0]?.dataset.code || '');
  const isOT = () => ['OT', 'POT'].includes(type.selectedOptions[0]?.dataset.code || '');
  const matches = () => context && context.date === date.value && (!cutoff.value || Number(cutoff.value) === Number(context.cutoff_id));
  const clock = (value) => {
    if (!value) return 'Missing';
    const parts = value.slice(11, 16).split(':').map(Number);
    return (parts[0] % 12 || 12) + ':' + String(parts[1]).padStart(2, '0') + (parts[0] >= 12 ? ' PM' : ' AM');
  };
  function sync() {
    const active = isTA();
    const dayReference = document.getElementById('employee-attendance-request-context');
    if (dayReference) dayReference.hidden = active || dayReference.dataset.date !== date.value;
    const ready = matches() && !loading && !failed;
    const missing = ready ? context.missing : [];
    fields.forEach(function (field) {
      const name = field.dataset.taField;
      const input = field.querySelector('input');
      const known = ready && context.values[name];
      field.hidden = !ready || !!known;
      input.disabled = !active || !ready || !!known;
      input.required = active && ready && !known && missing.length === 1;
      input.setCustomValidity('');
      block.querySelector('[data-ta-clock="' + name + '"]').textContent = ready ? clock(context.values[name]) : '—';
    });
    block.setAttribute('aria-busy', loading ? 'true' : 'false');
    retry.hidden = !failed;
    if (loading) message.textContent = 'Loading your logs…';
    else if (failed) message.textContent = 'Could not load your logs. Choose Reload logs to try again.';
    else if (!date.value) message.textContent = 'Choose an affected date to see your logs.';
    else if (!ready) message.textContent = 'Load the logs for this date before filing TA.';
    else message.textContent = missing.length ? 'Add missing ' + missing.map(name => labels[name]).join(', ') + '. Your saved times are kept.' : 'All four logs are saved. No TA is needed.';
    if (submit) submit.disabled = active && (!ready || missing.length === 0);
    syncOT();
  }
  async function load() {
    if (controller) controller.abort();
    const request = ++sequence;
    if (!date.value) { context = null; loading = false; failed = false; sync(); return; }
    controller = new AbortController();
    loading = true; failed = false; sync();
    const endpoint = new URL(block.dataset.taContextUrl, window.location.href);
    endpoint.searchParams.set('date', date.value);
    if (cutoff.value) endpoint.searchParams.set('cutoff_id', cutoff.value);
    try {
      const response = await fetch(endpoint, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: {Accept: 'application/json'}});
      if (!response.ok) throw new Error('Unable to load attendance');
      const data = await response.json();
      if (!Array.isArray(data.missing) || !data.values || data.date !== date.value) throw new Error('Invalid attendance response');
      if (request !== sequence) return;
      context = data; loading = false; sync();
    } catch (error) {
      if (request !== sequence || error.name === 'AbortError') return;
      context = null; loading = false; failed = true; sync();
    }
  }
  function syncOT() {
    const start = form.elements.namedItem('ot_start');
    const end = form.elements.namedItem('ot_end');
    const active = isOT();
    start.required = end.required = active;
    end.setCustomValidity('');
    if (!active || !date.value || !start.value || !end.value) return;
    const startDate = form.elements.namedItem('ot_start_date').value || date.value;
    const endDateInput = form.elements.namedItem('ot_end_date').value;
    const endDate = endDateInput || startDate;
    const started = Date.parse(startDate + 'T' + start.value + ':00Z');
    let ended = Date.parse(endDate + 'T' + end.value + ':00Z');
    if (!endDateInput && ended < started) ended += 86400000;
    if (Number.isFinite(started) && Number.isFinite(ended) && ended - started <= 3600000) {
      end.setCustomValidity('OT must be longer than 1 hour. Enter the actual work start and end times.');
    }
  }
  type.addEventListener('change', function () { sync(); if (isTA() && !matches()) load(); });
  date.addEventListener('change', function () {
    drafts.set(lastDate, Object.fromEntries(fields.map(field => [field.dataset.taField, field.querySelector('input').value])));
    lastDate = date.value;
    const saved = drafts.get(lastDate) || {};
    fields.forEach(field => { field.querySelector('input').value = saved[field.dataset.taField] || ''; });
    load();
  });
  cutoff.addEventListener('change', load);
  retry.addEventListener('click', load);
  form.addEventListener('input', function () { fields.forEach(field => field.querySelector('input').setCustomValidity('')); syncOT(); });
  form.addEventListener('submit', function (event) {
    if (isTA()) {
      const editable = fields.map(field => field.querySelector('input')).filter(input => !input.disabled);
      if (!matches() || loading || failed || !editable.length) { event.preventDefault(); sync(); return; }
      if (!editable.some(input => input.value)) {
        event.preventDefault(); editable[0].setCustomValidity('Enter an actual time for at least one missing log.'); editable[0].reportValidity(); return;
      }
    }
    syncOT();
    if (!form.checkValidity()) { event.preventDefault(); form.reportValidity(); }
  });
  sync();
  if (isTA() && !matches()) load();
})();
