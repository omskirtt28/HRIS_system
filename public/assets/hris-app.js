'use strict';

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
