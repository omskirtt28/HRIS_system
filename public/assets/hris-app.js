'use strict';

// Foldable sidebar groups: the group holding the current page is always open; other groups remember what was opened.
// Runs before the scroll restore below so the restored position matches the final layout.
(() => {
  const side = document.querySelector('#side[data-scroll-key]');
  if (!side) return;
  const key = 'hris-nav-open:' + side.dataset.scrollKey;
  let opened = [];
  try { opened = JSON.parse(localStorage.getItem(key) || '[]'); } catch (_) { /* Storage can be disabled. */ }
  if (!Array.isArray(opened)) opened = [];
  const set = (group, open) => {
    group.classList.toggle('is-collapsed', !open);
    group.querySelector('.gl-toggle')?.setAttribute('aria-expanded', String(open));
  };
  const save = () => {
    const names = [...side.querySelectorAll('.nav-group.foldable:not(.is-collapsed)')].map(g => g.dataset.navGroup);
    try { localStorage.setItem(key, JSON.stringify(names)); } catch (_) { /* Storage can be disabled. */ }
  };
  // Rail mode: a group is one icon and its items open in a fixed flyout beside it (fixed so the rail's overflow cannot clip it).
  const railed = () => document.documentElement.dataset.side === 'folded';
  let flyTimer = 0;
  const closeFly = () => side.querySelectorAll('.nav-group.fly').forEach(g => {
    g.classList.remove('fly');
    g.querySelector('.gl-toggle')?.setAttribute('aria-expanded', String(!g.classList.contains('is-collapsed')));
  });
  const openFly = group => {
    clearTimeout(flyTimer);
    closeFly();
    group.classList.add('fly');
    const btn = group.querySelector('.gl-toggle');
    btn?.setAttribute('aria-expanded', 'true');
    const items = group.querySelector('.nav-items');
    const r = btn.getBoundingClientRect();
    items.style.left = (r.right + 6) + 'px';
    // Keep the flyout on screen when the group sits near the bottom of the viewport.
    items.style.top = Math.max(8, Math.min(r.top, innerHeight - items.offsetHeight - 8)) + 'px';
  };
  const closeFlySoon = () => { clearTimeout(flyTimer); flyTimer = setTimeout(closeFly, 160); };
  side.querySelectorAll('.nav-group.foldable').forEach(group => {
    if (opened.includes(group.dataset.navGroup)) set(group, true);
    group.querySelector('.gl-toggle')?.addEventListener('click', () => {
      if (railed()) { group.classList.contains('fly') ? closeFly() : openFly(group); return; }
      set(group, group.classList.contains('is-collapsed'));
      save();
    });
    group.addEventListener('mouseenter', () => { if (railed()) openFly(group); });
    group.addEventListener('mouseleave', () => { if (railed()) closeFlySoon(); });
    group.addEventListener('focusin', () => { if (railed()) openFly(group); });
    group.addEventListener('focusout', e => { if (railed() && !group.contains(e.relatedTarget)) closeFlySoon(); });
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFly(); });
  side.addEventListener('scroll', closeFly, { passive: true });
  // Folded groups still surface unread counts on their header.
  window.refreshNavGroupCounts = () => side.querySelectorAll('.nav-group.foldable').forEach(group => {
    const total = [...group.querySelectorAll('[data-sidebar-count]')].reduce((sum, b) => sum + (b.hidden ? 0 : parseInt(b.textContent, 10) || 0), 0);
    const chip = group.querySelector('[data-group-count]');
    if (!chip) return;
    chip.textContent = total > 99 ? '99+' : String(total);
    chip.hidden = total < 1;
  });
})();

// The sidebar itself is the scrolling element; restore before the rest of the page controls.
(() => {
  const side = document.querySelector('#side[data-scroll-key]');
  if (!side) return;
  const restore = () => {
    try {
      const saved = Number(sessionStorage.getItem(side.dataset.scrollKey));
      if (Number.isFinite(saved) && saved >= 0) side.scrollTop = saved;
    } catch (_) { /* Navigation also works when storage is blocked. */ }
  };
  const save = () => {
    try { sessionStorage.setItem(side.dataset.scrollKey, String(side.scrollTop)); }
    catch (_) { /* Storage can be disabled. */ }
  };
  restore();
  window.addEventListener('pageshow', restore);
  side.addEventListener('scroll', save, { passive: true });
  side.addEventListener('click', save);
  window.addEventListener('pagehide', save);
})();

// Shared portal controls referenced by View.php.
window.toggleSidebar = () => {
  const side = document.getElementById('side');
  const open = side?.classList.toggle('open') ?? false;
  document.querySelector('.mobtoggle')?.setAttribute('aria-expanded', String(open));
};
// Desktop rail: html[data-side=folded] drives the CSS; the .nl labels stay for screen readers and a fixed tooltip names each icon (the rail's overflow would clip a CSS one).
let sideTip = null;
const showSideTip = link => {
  if (document.documentElement.dataset.side !== 'folded') return;
  const label = link.querySelector('.nl')?.textContent.trim();
  if (!label || link.closest('.foldable')) return;
  if (!sideTip) { sideTip = document.createElement('div'); sideTip.className = 'side-tip'; sideTip.setAttribute('aria-hidden', 'true'); document.body.appendChild(sideTip); }
  sideTip.textContent = label;
  const r = link.getBoundingClientRect();
  sideTip.style.left = (r.right + 10) + 'px';
  sideTip.style.top = (r.top + r.height / 2) + 'px';
  sideTip.classList.add('show');
};
const hideSideTip = () => sideTip?.classList.remove('show');
const SIDE_TIP_TARGET = '.side .nav-item, .side .sidebar-logout';
['mouseover', 'focusin'].forEach(ev => document.addEventListener(ev, e => { const l = e.target.closest?.(SIDE_TIP_TARGET); if (l) showSideTip(l); }));
['mouseout', 'focusout'].forEach(ev => document.addEventListener(ev, e => { if (e.target.closest?.(SIDE_TIP_TARGET)) hideSideTip(); }));
document.addEventListener('scroll', hideSideTip, true);
document.addEventListener('keydown', e => { if (e.key === 'Escape') hideSideTip(); });
const applySideFold = folded => {
  const root = document.documentElement;
  if (folded) root.dataset.side = 'folded'; else delete root.dataset.side;
  hideSideTip();
  const btn = document.querySelector('.sidefold');
  btn?.setAttribute('aria-expanded', String(!folded));
  btn?.setAttribute('aria-label', folded ? 'Expand sidebar' : 'Collapse sidebar');
};
window.toggleSideFold = () => {
  const folded = document.documentElement.dataset.side !== 'folded';
  applySideFold(folded);
  try { localStorage.setItem('hris-side', folded ? 'folded' : 'open'); } catch (_) { /* Storage can be disabled. */ }
};
if (document.documentElement.dataset.side === 'folded') applySideFold(true);
window.toggleProfileMenu = () => {
  const open = document.getElementById('profilePop')?.classList.toggle('open') ?? false;
  document.querySelector('.profile-trigger')?.setAttribute('aria-expanded', String(open));
};
window.toggleTheme = () => {
  const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
  document.documentElement.dataset.theme = theme;
  try { localStorage.setItem('hris-theme', theme); } catch (_) { /* Storage can be disabled. */ }
};
try {
  const saved = localStorage.getItem('hris-theme');
  if (saved === 'dark' || saved === 'light') document.documentElement.dataset.theme = saved;
} catch (_) { /* Use the default theme. */ }
document.addEventListener('click', event => {
  if (!event.target.closest('.profile-menu')) {
    document.getElementById('profilePop')?.classList.remove('open');
    document.querySelector('.profile-trigger')?.setAttribute('aria-expanded', 'false');
  }
});
document.addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  document.getElementById('side')?.classList.remove('open');
  document.getElementById('profilePop')?.classList.remove('open');
  document.querySelector('.mobtoggle')?.setAttribute('aria-expanded', 'false');
  document.querySelector('.profile-trigger')?.setAttribute('aria-expanded', 'false');
});
const hrisClock = document.getElementById('manilaClock');
if (hrisClock) {
  const heading = document.createElement('strong');
  heading.textContent = 'Philippine Time';
  const value = document.createElement('span');
  hrisClock.replaceChildren(heading, value);
  const format = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric',
    hour: 'numeric', minute: '2-digit', hour12: true
  });
  const update = () => { value.textContent = format.format(new Date()); };
  update();
  setInterval(update, 30000);
}

/* Notification bell: unread badge, latest 10, mark as read. */
(() => {
  const toggle = document.querySelector('[data-notify-toggle]');
  if (!toggle) return;
  const panel = document.querySelector('[data-notify-panel]');
  const list = document.querySelector('[data-notify-list]');
  const badge = document.querySelector('[data-notify-badge]');
  const readAll = document.querySelector('[data-notify-readall]');
  const error = document.createElement('div');
  error.className = 'notify-error'; error.setAttribute('role', 'status'); error.hidden = true;
  list.before(error);
  const showError = message => { error.textContent = message; error.hidden = !message; };
  const setBadge = count => {
    if (!badge) return;
    badge.textContent = count > 99 ? '99+' : String(count);
    badge.hidden = count < 1;
    toggle.setAttribute('aria-label', `Notifications, ${count} unread`);
  };
  const setCounts = data => {
    setBadge(data.unread);
    document.querySelectorAll('[data-sidebar-count]').forEach(badge => {
      const count = data.sidebar_counts[badge.dataset.sidebarCount] || 0;
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.hidden = count < 1;
      badge.setAttribute('aria-label', `${count} unread notifications`);
    });
    window.refreshNavGroupCounts?.();
  };
  const validCounts = data => Number.isInteger(data.unread) && data.unread >= 0 &&
    data.sidebar_counts && typeof data.sidebar_counts === 'object' &&
    Object.values(data.sidebar_counts).every(count => Number.isInteger(count) && count >= 0);
  let writing = false;
  let revision = 0;
  const mark = async id => {
    if (writing) return false;
    writing = true; readAll.disabled = true;
    revision++;
    showError('');
    const body = new FormData();
    body.append('action', 'notification_read'); body.append('csrf', toggle.dataset.csrf || ''); body.append('id', String(id));
    try {
      const res = await fetch('index.php', { method: 'POST', body, credentials: 'same-origin' });
      const data = await res.json();
      if (!res.ok || data.ok !== true || !validCounts(data)) throw new Error('Read failed');
      setCounts(data);
      return true;
    } catch (_) { showError('Could not mark notifications as read. Please try again.'); return false; }
    finally { writing = false; readAll.disabled = false; }
  };
  const render = data => {
    list.replaceChildren();
    if (!data.items.length) { const empty = document.createElement('div'); empty.className = 'notify-empty'; empty.textContent = 'No notifications yet.'; list.append(empty); }
    data.items.forEach(item => {
      const row = document.createElement(item.link ? 'a' : 'div');
      row.className = 'notify-item' + (item.read ? '' : ' unread');
      if (item.link) row.href = item.link;
      const title = document.createElement('strong'); title.textContent = item.title;
      const text = document.createElement('span'); text.textContent = item.body;
      const when = document.createElement('small'); when.textContent = item.when;
      row.append(title, text, when);
      row.addEventListener('click', async event => {
        // Wait for read confirmation before navigating so the next page has the same counts.
        if (item.read) return;
        event.preventDefault();
        if (await mark(item.id)) {
          item.read = true; row.classList.remove('unread');
          if (item.link) window.location.assign(row.href);
        }
      });
      list.append(row);
    });
    setCounts(data);
  };
  const load = async () => {
    if (writing) return;
    const current = ++revision;
    try {
      const res = await fetch(toggle.dataset.url, { credentials: 'same-origin' });
      const data = await res.json();
      if (!res.ok || !validCounts(data) || !Array.isArray(data.items)) throw new Error('Load failed');
      if (current === revision) render(data);
    }
    catch (_) { if (current === revision) list.textContent = 'Could not load notifications.'; }
  };
  toggle.addEventListener('click', event => {
    event.stopPropagation();
    const open = panel.hidden;
    panel.hidden = !open; toggle.setAttribute('aria-expanded', String(open));
    if (open) load();
  });
  readAll?.addEventListener('click', async () => { if (await mark(0)) load(); });
  document.addEventListener('click', event => { if (!event.target.closest('.notify-wrap')) { panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); } });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') { panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); } });
})();
