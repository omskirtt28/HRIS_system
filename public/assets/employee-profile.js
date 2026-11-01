// My Profile tabs. Without this script every panel stays visible and the tab links jump to their panel.
(function () {
  var root = document.querySelector('.myp-tabs');
  if (!root) return;
  var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));
  if (!tabs.length) return;
  root.classList.add('myp-js');
  function show(tab, focus) {
    tabs.forEach(function (t) {
      var on = t === tab;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
    });
    if (focus) tab.focus();
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function (ev) { ev.preventDefault(); show(t); try { history.replaceState(null, '', t.getAttribute('href')); } catch (e) {} });
    t.addEventListener('keydown', function (ev) {
      var n = ev.key === 'ArrowRight' ? i + 1 : ev.key === 'ArrowLeft' ? i - 1 : ev.key === 'Home' ? 0 : ev.key === 'End' ? tabs.length - 1 : -1;
      if (n < 0) return;
      ev.preventDefault();
      show(tabs[(n + tabs.length) % tabs.length], true);
    });
  });
  var hash = location.hash.replace('#', '');
  show(tabs.filter(function (t) { return t.getAttribute('aria-controls') === hash; })[0] || tabs[0]);
})();

// Print Summary: opens every tab for printing (the print stylesheet hides the tab bar and controls).
document.addEventListener('click', function (ev) {
  if (ev.target.closest('[data-print]')) window.print();
});

// Government IDs: reveal on demand, re-mask after 30 s. The full number is never in the page HTML.
(function () {
  var list = document.querySelector('.myp-idlist');
  if (!list) return;
  var live = document.querySelector('.myp-live');
  function say(msg) { if (live) live.textContent = msg; }
  function mask(btn, code) {
    code.textContent = code.getAttribute('data-masked');
    code.classList.remove('is-open');
    btn.textContent = 'Show';
    btn.setAttribute('aria-label', btn.getAttribute('aria-label').replace(/^Hide/, 'Show'));
    clearTimeout(btn._t);
  }
  // A revealed number must never reach the printout.
  window.addEventListener('beforeprint', function () {
    list.querySelectorAll('.myp-idnum.is-open').forEach(function (code) { mask(code.closest('.myp-id').querySelector('.myp-reveal'), code); });
  });
  list.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.myp-reveal');
    if (!btn) return;
    var code = btn.closest('.myp-id').querySelector('.myp-idnum');
    if (code.classList.contains('is-open')) { mask(btn, code); return; }
    var body = new FormData();
    body.append('csrf', list.getAttribute('data-csrf'));
    body.append('id', btn.getAttribute('data-id'));
    btn.disabled = true;
    say('');
    fetch(list.getAttribute('data-url'), { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.error || 'Could not show this number.'); return j; }); })
      .then(function (j) {
        // An expired session is redirected to the login page (200, not JSON): never show an empty value as if it were the number.
        if (typeof j.number !== 'string' || j.number === '') throw new Error('Your session may have expired. Reload the page and sign in again.');
        code.textContent = j.number;
        code.classList.add('is-open');
        btn.textContent = 'Hide';
        btn.setAttribute('aria-label', btn.getAttribute('aria-label').replace(/^Show/, 'Hide'));
        btn._t = setTimeout(function () { mask(btn, code); }, 30000);
      })
      .catch(function (e) { say(e.message || 'Could not show this number.'); })
      .then(function () { btn.disabled = false; });
  });
})();

// Documents: type filter and image preview.
(function () {
  var box = document.querySelector('.myp-docs');
  if (!box) return;
  var chips = box.querySelectorAll('.myp-chip'), items = box.querySelectorAll('.myp-doc');
  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      var f = c.getAttribute('data-filter');
      chips.forEach(function (o) { var on = o === c; o.classList.toggle('is-on', on); o.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      items.forEach(function (li) { li.hidden = f !== 'all' && li.getAttribute('data-type') !== f; });
    });
  });
  var dlg = box.querySelector('.myp-lightbox');
  if (!dlg || typeof dlg.showModal !== 'function') return; // without <dialog> the link opens the image in a new tab
  var img = dlg.querySelector('img'), cap = dlg.querySelector('.myp-lb-cap');
  box.addEventListener('click', function (ev) {
    var a = ev.target.closest('[data-lightbox]');
    if (!a) return;
    ev.preventDefault();
    img.src = a.getAttribute('href'); img.alt = a.getAttribute('data-title') || ''; cap.textContent = a.getAttribute('data-title') || '';
    dlg.showModal();
  });
  dlg.querySelector('.myp-lb-close').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (ev) { if (ev.target === dlg) dlg.close(); });
  dlg.addEventListener('close', function () { img.removeAttribute('src'); });
})();
