'use strict';

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
  const setBadge = count => {
    if (!badge) return;
    badge.textContent = count > 99 ? '99+' : String(count);
    badge.hidden = count < 1;
  };
  const mark = async id => {
    const body = new FormData();
    body.append('action', 'notification_read'); body.append('csrf', toggle.dataset.csrf || ''); body.append('id', String(id));
    try { const res = await fetch('index.php', { method: 'POST', body, credentials: 'same-origin' }); const data = await res.json(); setBadge(data.unread || 0); } catch (_) { /* The next load shows the true count. */ }
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
      row.addEventListener('click', () => { if (!item.read) { row.classList.remove('unread'); mark(item.id); } });
      list.append(row);
    });
    setBadge(data.unread || 0);
  };
  const load = async () => {
    try { const res = await fetch(toggle.dataset.url, { credentials: 'same-origin' }); render(await res.json()); }
    catch (_) { list.textContent = 'Could not load notifications.'; }
  };
  toggle.addEventListener('click', event => {
    event.stopPropagation();
    const open = panel.hidden;
    panel.hidden = !open; toggle.setAttribute('aria-expanded', String(open));
    if (open) load();
  });
  readAll?.addEventListener('click', async () => { await mark(0); load(); });
  document.addEventListener('click', event => { if (!event.target.closest('.notify-wrap')) { panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); } });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') { panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); } });
})();
