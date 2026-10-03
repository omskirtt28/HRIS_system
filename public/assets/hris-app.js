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
