# SPEC: sidebar-fold-rail

Status: BUILT 2026-10-31 (Q1: logo+scrollbar+tooltip, toggle stays in topbar; Q2: JS fixed tooltip). Browser check pending.

## 1. Objective
The folded (icon-rail) sidebar looks off (John's screenshot 2026-10-31, HR portal, 88px rail). Fix the rail only; the open sidebar and nav_config stay untouched.

Seen in the screenshot
1. The logo sits tight to the left edge and looks clipped.
2. A native scrollbar shows inside the 88px rail and eats the right padding, so icons are off-centre.
3. Many icons look identical (3x people, 5x document) and there is no label: user cannot tell them apart.
4. The fold toggle lives in the topbar, far from the rail.

Acceptance criteria
1. Logo fully visible, centred, not cut, with the same gap on both sides of the rail.
2. No visible scrollbar in the rail; it still scrolls by wheel/keyboard (`scrollbar-width:none`).
3. Every rail item shows its label on hover AND keyboard focus (tooltip to the right of the rail, not clipped by `overflow-x:hidden`); the name is also exposed as `title`/accessible name.
4. Active item, unread badge and Soon/disabled items stay distinguishable in the rail.
5. Open sidebar, mobile drawer (<=900px), dark mode unchanged.
6. Reduced motion respected; tooltip text contrast >= 4.5:1.

## 2. Commands
`php -l app/View.php`, `node --check public/assets/hris-app.js`, `php tests/notification_sidebar_test.php`, `php scripts/check.php task`

## 3. Project structure
`public/assets/sidebar-fold.css` (rail rules), `app/View.php` (add `data-tip` to nav items/logout, bump `?v=`), `public/assets/hris-app.js` only if a JS-positioned tooltip is needed (CSS-only preferred; `.side` overflow clips an absolute tooltip, so use `position:fixed` + JS or drop `overflow-x:hidden` on the rail).

## 4. Boundaries
Always: English UI text, vault sync, keep `.nl` labels in the a11y tree.
Ask first: moving the fold toggle into the sidebar, changing icons per module.
Never: permissions, routes, nav_config, commit/push.

## 5. Open questions
Q1 Confirm the "off" is items 1-3 (the toggle move is optional).
Q2 Tooltip: CSS-only (simple, may need overflow change) or JS fixed-position (robust)?
