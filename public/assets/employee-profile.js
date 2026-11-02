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

// Profile photo: view the current photo, or pick a new one, drag/zoom it into a square crop and upload the cropped JPEG.
(function () {
  var form = document.querySelector('.myp-avatar-form');
  var dlg = form && form.querySelector('.myp-photo-dlg');
  if (!dlg || typeof dlg.showModal !== 'function') return; // without <dialog> nothing opens
  var S = 280, OUT = 512;
  var stage = dlg.querySelector('.myp-crop'), zoomWrap = dlg.querySelector('.myp-zoom'), zoom = zoomWrap.querySelector('input');
  var msg = dlg.querySelector('.myp-photo-msg'), file = form.querySelector('#mypPhotoFile');
  var btnPick = dlg.querySelector('[data-photo-pick]'), btnSave = dlg.querySelector('[data-photo-save]'), btnClose = dlg.querySelector('[data-photo-close]');
  var img = stage.querySelector('img'), firstHtml = stage.innerHTML;
  var src = dlg.getAttribute('data-src'), crop = null, url = null;
  function say(t) { msg.textContent = t || ''; msg.hidden = !t; }
  function size() { S = stage.clientWidth || 280; }
  function view() {
    crop = null; dlg.classList.remove('is-crop'); zoomWrap.hidden = true; btnSave.hidden = true; btnPick.textContent = src ? 'Change photo' : 'Add photo';
    if (url) { URL.revokeObjectURL(url); url = null; }
    if (!src && img) { stage.innerHTML = firstHtml; img = null; } // no saved photo: back to the initials
    else if (img) { img.src = src; img.removeAttribute('style'); }
    stage.removeAttribute('aria-roledescription');
  }
  function place() {
    var w = crop.w * crop.scale, h = crop.h * crop.scale;
    crop.x = Math.min(0, Math.max(S - w, crop.x)); crop.y = Math.min(0, Math.max(S - h, crop.y));
    img.style.width = w + 'px'; img.style.height = h + 'px'; img.style.transform = 'translate(' + crop.x + 'px,' + crop.y + 'px)';
  }
  function setZoom(z) { // keeps the centre of the frame fixed
    var cx = (S / 2 - crop.x) / crop.scale, cy = (S / 2 - crop.y) / crop.scale;
    crop.scale = crop.base * z; crop.x = S / 2 - cx * crop.scale; crop.y = S / 2 - cy * crop.scale; place();
  }
  function start(f) {
    say('');
    if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { say('Choose a JPG, PNG or WEBP photo.'); return; }
    if (url) URL.revokeObjectURL(url);
    url = URL.createObjectURL(f);
    var probe = new Image();
    probe.onload = function () {
      if (!img) { img = document.createElement('img'); img.className = 'myp-crop-img'; img.alt = ''; img.draggable = false; stage.textContent = ''; stage.appendChild(img); }
      dlg.classList.add('is-crop'); size();
      img.removeAttribute('style'); img.src = url;
      var base = S / Math.min(probe.naturalWidth, probe.naturalHeight);
      crop = { w: probe.naturalWidth, h: probe.naturalHeight, base: base, scale: base, x: 0, y: 0 };
      crop.x = (S - crop.w * base) / 2; crop.y = (S - crop.h * base) / 2; place();
      zoom.value = 1; zoomWrap.hidden = false; btnSave.hidden = false; btnPick.textContent = 'Choose another';
      stage.focus();
    };
    probe.onerror = function () { say('This photo could not be read. Try a JPG or PNG.'); };
    probe.src = url;
  }
  form.querySelector('[data-photo-open]').addEventListener('click', function () { say(''); view(); dlg.showModal(); });
  btnPick.addEventListener('click', function () { file.value = ''; file.click(); });
  file.addEventListener('change', function () { if (file.files[0]) start(file.files[0]); });
  btnClose.addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (ev) { if (ev.target === dlg) dlg.close(); });
  dlg.addEventListener('close', function () { if (url) { URL.revokeObjectURL(url); url = null; } file.value = ''; });
  zoom.addEventListener('input', function () { if (crop) setZoom(parseFloat(zoom.value)); });
  var drag = null;
  stage.addEventListener('pointerdown', function (ev) { if (!crop) return; drag = { px: ev.clientX, py: ev.clientY, x: crop.x, y: crop.y }; stage.setPointerCapture(ev.pointerId); });
  stage.addEventListener('pointermove', function (ev) { if (!drag) return; crop.x = drag.x + ev.clientX - drag.px; crop.y = drag.y + ev.clientY - drag.py; place(); });
  stage.addEventListener('pointerup', function () { drag = null; });
  stage.addEventListener('pointercancel', function () { drag = null; });
  stage.addEventListener('keydown', function (ev) {
    if (!crop) return;
    var d = { ArrowLeft: [10, 0], ArrowRight: [-10, 0], ArrowUp: [0, 10], ArrowDown: [0, -10] }[ev.key];
    if (!d) return;
    ev.preventDefault(); crop.x += d[0]; crop.y += d[1]; place();
  });
  btnSave.addEventListener('click', function () {
    if (!crop) return;
    var c = document.createElement('canvas'); c.width = c.height = OUT;
    var g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, OUT, OUT);
    g.drawImage(img, -crop.x / crop.scale, -crop.y / crop.scale, S / crop.scale, S / crop.scale, 0, 0, OUT, OUT);
    btnSave.disabled = true; btnSave.textContent = 'Saving...';
    c.toBlob(function (blob) {
      if (!blob) { say('Could not prepare the photo. Try another one.'); btnSave.disabled = false; btnSave.textContent = 'Save photo'; return; }
      var dt = new DataTransfer(); dt.items.add(new File([blob], 'profile.jpg', { type: 'image/jpeg' }));
      file.files = dt.files; form.submit();
    }, 'image/jpeg', 0.9);
  });
})();
