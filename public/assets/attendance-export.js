// Progressive UI only. PHP controls current reports and final export locking.
document.querySelectorAll('[data-attendance-export]').forEach(function (workspace) {
  const selector = workspace.querySelector('[data-export-cutoff] select');
  if (selector) selector.addEventListener('change', function () { selector.form.requestSubmit(); });
  const confirmation = workspace.querySelector('[data-export-confirm]');
  const exportPanel = workspace.querySelector('[data-export-panel]');
  const exportLauncher = workspace.querySelector('[data-export-launch]');
  let downloadOpener = null;
  function showExport(focus) {
    if (!exportPanel) return;
    if (confirmation) confirmation.hidden = true;
    exportPanel.hidden = false;
    if (exportLauncher) exportLauncher.setAttribute('aria-expanded', 'true');
    exportPanel.scrollIntoView({ block: 'nearest' });
    const firstFormat = exportPanel.querySelector('.export-format');
    if (focus && firstFormat) firstFormat.focus({ preventScroll: true });
  }
  function closeExport() {
    if (exportPanel) exportPanel.hidden = true;
    if (exportLauncher) { exportLauncher.setAttribute('aria-expanded', 'false'); exportLauncher.focus(); }
  }
  function cancelDownload() {
    if (!confirmation) return;
    confirmation.hidden = true;
    showExport(false);
    if (downloadOpener) downloadOpener.focus({ preventScroll: true });
  }
  if (exportPanel) {
    exportPanel.hidden = true;
    if (exportLauncher) exportLauncher.setAttribute('aria-expanded', 'false');
    exportPanel.querySelectorAll('[data-export-close], [data-export-download]').forEach(function (button) { button.hidden = false; });
  }
  function selectTab(name, focus) {
    workspace.querySelectorAll('[data-export-tab]').forEach(function (button) {
      const active = button.dataset.exportTab === name;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', String(active));
      button.tabIndex = active ? 0 : -1;
      if (active && focus) button.focus();
    });
    workspace.querySelectorAll('[data-export-tab-panel]').forEach(function (panel) { panel.hidden = panel.dataset.exportTabPanel !== name; });
  }
  function openTarget(target) {
    if (!target) return;
    const tab = target.closest('[data-export-tab-panel]');
    if (tab) selectTab(tab.dataset.exportTabPanel, false);
    let node = target;
    while (node && node !== workspace) {
      if (node instanceof HTMLDetailsElement) node.open = true;
      node = node.parentElement;
    }
    target.scrollIntoView({ block: 'nearest' });
    const summary = target instanceof HTMLDetailsElement ? target.querySelector('summary') : null;
    if (summary) summary.focus({ preventScroll: true });
  }
  workspace.addEventListener('click', function (event) {
    if (event.target.closest('[data-export-launch]') && exportPanel) { event.preventDefault(); showExport(true); return; }
    if (event.target.closest('[data-export-close]')) { closeExport(); return; }
    const tab = event.target.closest('[data-export-tab]');
    if (tab) { selectTab(tab.dataset.exportTab, false); return; }
    const open = event.target.closest('[data-export-open]');
    if (open) {
      event.preventDefault();
      openTarget(document.getElementById(open.getAttribute('href').slice(1)));
      return;
    }
    const download = event.target.closest('[data-export-download]');
    if (download && confirmation) {
      event.preventDefault();
      downloadOpener = download;
      const format = download.dataset.exportDownload;
      if (!['xlsx', 'pdf'].includes(format)) return;
      confirmation.querySelector('[data-export-format]').value = format;
      confirmation.querySelectorAll('[data-export-format-label]').forEach(function (label) { label.textContent = format === 'xlsx' ? 'Excel' : 'PDF'; });
      if (exportPanel) exportPanel.hidden = true;
      if (exportLauncher) exportLauncher.setAttribute('aria-expanded', 'false');
      confirmation.hidden = false;
      confirmation.scrollIntoView({ block: 'nearest' });
      confirmation.querySelector('[data-export-confirm-download]').focus({ preventScroll: true });
      return;
    }
    if (event.target.closest('[data-export-cancel]') && confirmation) {
      cancelDownload();
    }
  });
  workspace.addEventListener('toggle', function (event) {
    if (!(event.target instanceof HTMLDetailsElement) || !event.target.classList.contains('export-issue')) return;
    const label = event.target.querySelector('[data-export-view-label]');
    if (label) label.textContent = event.target.open ? 'Close' : 'View';
  }, true);
  workspace.addEventListener('keydown', function (event) {
    const tab = event.target.closest('[data-export-tab]');
    if (tab && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
      event.preventDefault();
      const buttons = Array.from(workspace.querySelectorAll('[data-export-tab]'));
      const index = buttons.indexOf(tab);
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;
      selectTab(buttons[next].dataset.exportTab, true);
    }
    if (event.key === 'Escape' && confirmation && !confirmation.hidden) {
      cancelDownload();
    } else if (event.key === 'Escape' && exportPanel && !exportPanel.hidden) closeExport();
  });
  function openHash() {
    const target = document.getElementById(window.location.hash.slice(1));
    if (target && target === exportPanel) showExport(false); else openTarget(target);
  }
  if (window.location.hash) openHash();
  window.addEventListener('hashchange', openHash);
});
