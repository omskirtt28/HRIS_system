# SPEC: Collapsible sidebar (icon rail)

Status: DRAFT, awaiting John's confirmation. No code written yet.

## 1. Objective
Let any logged-in user fold the sidebar to the left into a slim icon rail. While folded, every navigation icon and its unread-notification badge stay visible; the main content widens to fill the freed space.

Users: Employee, HR, Admin and Payroll portal users (all share one sidebar in `app/View.php`, around lines 164-168).

Acceptance criteria
- A toggle button at the left of the topbar (desktop) folds/unfolds the sidebar. The existing mobile off-canvas button and `toggleSidebar()` behaviour are unchanged below the mobile breakpoint.
- Folded width is about 72px. Content area grows (`.shell` grid column changes), no horizontal page scroll.
- Folded: each `.nav-item` shows only its icon, centered. Group labels (`.gl`), item labels, "Soon" pills, brand text, profile name/role and logout label are hidden. A thin divider replaces each group label.
- Folded: the red `.sidebar-notify-badge` stays visible as a small dot/number pinned to the top-right corner of the icon, and still updates through the existing `data-sidebar-count` polling in `hris-app.js`. 99+ still capped.
- Folded: hovering or keyboard-focusing an item shows its label as a tooltip. Each folded link keeps an accessible name (`aria-label`/`title` = label, plus the unread count).
- Active item stays highlighted (the brand-coloured left bar still reads in the rail).
- Choice is remembered per browser (`localStorage`, wrapped in try/catch) and applied before first paint to avoid a flash. Default is expanded.
- Toggle has `aria-expanded`, `aria-controls="side"`, visible focus ring, and works with Enter/Space.
- Logo: folded state shows the full existing logo scaled to 68px wide (rail is 88px); no new image assets. Revised 2026-10-31 at John's request (was a round "p" crop in a 76px rail).
- Animated width transition ~160ms; respect `prefers-reduced-motion`.
- Light and dark theme both correct.

## 2. Commands
- Syntax check: `php -l app/View.php`
- Existing checks to keep green: `php tests/notification_sidebar_test.php`, `php tests/incident_ui_test.php` (sidebar assertions), `php tests/ticket_viewers_test.php`
- Browser/DB runs only if John allows; per AGENTS rules the default is static/syntax checks only, no-browser. Visual verification, if allowed: `run-hris-system` skill against the isolated scratch DB, never `pmbsi_hris`.

## 3. Project structure (files expected to change)
- `app/View.php` - add toggle button in the topbar; add `data-label` / `aria-label` / `title` on nav links; add an early inline snippet setting `html[data-side="folded"]` from localStorage; bump asset `?v=` strings.
- `public/assets/hris-modern.css` - folded-rail rules next to the existing `.side` / `.nav-item` / `.shell` rules (or a new small `sidebar-fold.css` loaded after it, matching how `sidebar-logout.css` was added).
- `public/assets/notifications.css` - badge repositioning when folded.
- `public/assets/hris-app.js` - toggle handler + persistence; keep the existing sidebar scroll-restore and badge polling untouched.
- `public/assets/sidebar-logout.css` - folded icon-only logout.
- Tests: extend `tests/notification_sidebar_test.php` (badge/markup contract). No DB or migration changes.
- Vault (mandatory): `HRIS-Vault/03-Design/Design-System.md` (sidebar fold pattern), `Screens.md`, module note if any, `Decision-Log.md`, `Progress.md`, `CHANGELOG.md`, `Session-Log.md`.

## 4. Code style
Match the surroundings: PHP template with `e()` escaping and `icon_svg()`, compact CSS using the `--hris-*` tokens, vanilla JS, no framework or new dependency. English-only UI text. Only a `data-side` attribute on `<html>` drives the state, so CSS does the work and JS stays about 15 lines.

## 5. Testing strategy
- Static: `php -l`, and the existing sidebar tests.
- Markup test: every non-disabled nav link has an accessible name and keeps its `data-sidebar-count` badge; toggle button has `aria-expanded` and `aria-controls`.
- Manual checklist (if browser is allowed): fold/unfold, reload keeps state, badge visible folded and updates, tooltip on hover and focus, active item visible, light/dark, 1280px and 1024px widths, mobile off-canvas unaffected, long sidebars still scroll, no horizontal scroll.

## 6. Boundaries
Always: keep permission checks and nav filtering as they are; escape output; keep notification polling working; follow `Design-System.md`; fix-sweep the same pattern (other fixed-width sidebar assumptions, e.g. `.shell` grid overrides in other CSS files); sync the vault.
Ask first: new image assets for the compact logo; any change to nav items/permissions; changing mobile behaviour.
Never: touch the database or migrations, change notification counting logic, hard-code user-specific state server-side, commit/push/deploy, or add a dependency.

## Out of scope
Expand-on-hover overlay, per-user server-side preference, redesign of the sidebar's colours or grouping.

## Assumptions
- Persistence is per browser, not per account.
- The notification bell in the topbar is unchanged.
