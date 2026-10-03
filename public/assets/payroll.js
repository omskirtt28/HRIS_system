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
