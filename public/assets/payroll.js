// Select visible records only. Server permissions and stage checks remain authoritative.
document.querySelectorAll('form[data-cutoff-select], form[data-hr-bulk-review]').forEach(function (form) {
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
