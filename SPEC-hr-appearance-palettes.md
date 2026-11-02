# SPEC: hr-appearance-palettes

Status: APPROVED 2026-11-02 (HR portal only; Admin excluded, Q1 closed). Plan and tasks written. No code yet.

## 1. Objective
John (screenshot of the top-right profile menu, circled "Appearance") wants Appearance to offer colour palettes, **for the HR role only**, taken from his reference images (Soft Beige, Soft Gradients, Earth Tones). Today Appearance is a one-click Light/Dark toggle (`toggleTheme()`, saved in `localStorage['hris-theme']`).

Decisions already made by John (2026-11-02, via questions):
1. Palettes offered: Light, Dark, **Soft Beige, Earth Tones, Soft Pastel** (Pastel is a toned-down flat version of the Soft Gradients image; recommended for readability).
2. Remembered **per HR user in the database** (follows the user across browsers/devices).
3. Reach: page background, cards/surfaces, topbar and sidebar (all `--hris-*` tokens). Status colours (success/info/danger/warning) and the amber brand fill do **not** change.
4. Non-HR roles: **unchanged** (current one-click Light/Dark).

Design recommendation (John asked "check mo ano babagay"): the PMBSI brand is amber `#f7a600` on a near-black sidebar. **Soft Beige** and **Earth Tones** keep amber legible and calm; **Soft Pastel** is the riskiest (low contrast), so it ships flat (no gradients) with a deeper sidebar. Gradients are avoided on content surfaces (Design-System: subtle or none).

### Assumptions (correct me now or I proceed)
1. "HR role" = the HR portal (`nav_config` kind `hr`, `role_portal='hr'`). Admin/Payroll/Manager/Employee keep Light/Dark. Open Q1 if Admin should also get palettes.
2. Light and Dark stay in the same picker for HR, so HR can still go dark.
3. Palette values below are estimated by eye from the images; they are **NEEDS VERIFICATION** until the contrast test passes. Adjust hex, never lower the 4.5:1 floor.
4. Palette is applied by `data-palette` on `<html>` (Light base + overrides); Dark remains `data-theme="dark"`.
5. UI text is English only.

### Palette tokens (all light-based; unlisted tokens inherit Light)
| Token | Beige | Earth | Pastel |
|---|---|---|---|
| `--hris-bg` | #f6efe6 | #eef0e4 | #f6f2fa |
| `--hris-surface` | #fffdf9 | #fbfaf4 | #ffffff |
| `--hris-surface-2` | #faf4ea | #f3f4ea | #faf7fd |
| `--hris-line` | #e6d9c8 | #d9dccb | #e6dff0 |
| `--hris-text` | #2b2620 | #262a22 | #26232e |
| `--hris-muted` | #6b6153 | #596050 | #655f73 |
| `--hris-brand-soft` | #fbeccb | #f4e0d2 | #fde8ee |
| `--hris-sidebar` | #3b2f25 | #34423a | #3a3550 |
| `--hris-sidebar-soft` | #4a3b2e | #415247 | #4a4464 |

Swatch shown in the menu = the palette's `--hris-bg` plus `--hris-sidebar` half-and-half.

### Acceptance criteria
1. For an HR user, Appearance opens a small list of 5 options (Light, Dark, Soft Beige, Earth Tones, Soft Pastel) with a swatch, label and a check on the active one. Non-HR users: Appearance still toggles Light/Dark directly, no list.
2. Choosing an option applies it instantly (no reload) and saves it to the user's account. The next page load, another browser and a new login all show it; no flash of the wrong palette (server writes `data-palette`/`data-theme` on `<html>`).
3. Default for every existing user is `light`; no existing user's look changes until they pick.
4. Every palette: body text and muted text >= 4.5:1 on bg, surface and surface-2; sidebar text >= 4.5:1 on sidebar and sidebar-soft; focus ring visible (3:1). Checked by an automated ratio test, not by eye.
5. Status badges/alerts and the amber brand button look the same in every palette and still meet their current contrast.
6. Keyboard: Appearance is a button with `aria-haspopup`/`aria-expanded`; options are radio-style menu items; Arrow keys move, Enter/Space choose, Esc closes and returns focus. Colour is never the only indicator (check mark + label).
7. Works at 320, 768, 1024, 1440 px; the popover is not clipped inside the profile menu.
8. A forged POST (non-HR user, unknown palette value, missing CSRF) changes nothing and returns an error.
9. Sidebar fold rail, mobile drawer, dark mode and all current pages render as before for Light/Dark.

## 2. Tech stack
PHP 8 (XAMPP), MySQL/MariaDB `pmbsi_hris`, plain CSS tokens (`public/assets/hris-modern.css`), vanilla JS (`public/assets/hris-app.js`). No new dependencies.

## 3. Commands
```
php -l app/View.php
php -l index.php
node --check public/assets/hris-app.js
php tests/hr_palette_test.php
php scripts/check.php task
HRIS_TEST_DB=<scratch> php scripts/check.php full     # before shipping
```
Migration cycle on a scratch DB first (up, re-run, down, up), then localhost `pmbsi_hris` only after a fresh mysqldump with verified restore (standing workflow).

## 4. Project structure
```
database/migrations/20261102_027_user_palette.sql / .down.sql   users.ui_palette VARCHAR(16) NOT NULL DEFAULT 'light'
app/UserPalette.php            whitelist, canChoose(user), save(userId, value), cssAttrs(user)
public/assets/palettes.css     [data-palette="beige|earth|pastel"] token blocks (loaded after hris-modern.css)
app/View.php                   render <html data-...>, Appearance list for HR, cache-bust ?v=
public/assets/hris-app.js      open/close list, apply, POST save (HR only); non-HR keeps toggleTheme()
index.php                      POST action set_palette (verify_csrf, own user only)
tests/hr_palette_test.php      whitelist, role gate, save, contrast ratios
```
No change to roles, permissions, nav_config or routes (one POST action only).

## 5. Code style
Follow existing View.php inline-PHP + `e()` escaping and the `hris-app.js` style:
```js
window.setPalette = async key => {
  document.documentElement.dataset.theme = key === 'dark' ? 'dark' : 'light';
  document.documentElement.dataset.palette = ['beige','earth','pastel'].includes(key) ? key : '';
  // POST action=set_palette with the CSRF token; on failure revert and show a toast
};
```
Tokens only (no raw hex outside `palettes.css`), 0.25rem spacing scale, radius per Design-System.

## 6. Testing strategy
- Unit (`tests/hr_palette_test.php`): whitelist rejects unknown values; non-HR cannot save; HR saves and reads back; default `light`.
- Contrast test in the same file: parse the token blocks from `palettes.css`, compute WCAG ratios for each palette pair in AC4, fail below 4.5.
- HTTP render test (scratch DB): HR page contains the 5 options and `data-palette` for a saved user; employee page has none; forged POST rejected.
- Browser check (`run-hris-system`, scratch DB): screenshots of dashboard, a directory table and a form in each palette at 1440 and 375 px; keyboard walkthrough. John does the localhost eyeball.
- Ratchet: add tests to `scripts/check.php` floors; never lower one.

## 7. Boundaries
- Always: English UI text; CSRF + server-side role check on save; escape output; vault sync (Progress, Screens, Data-Model for the column, Business-Rules, Decision-Log, Open-Questions, CHANGELOG, Session-Log); fix sweep (other hard-coded colours that ignore tokens in HR pages).
- Ask first: giving palettes to Admin/other roles; adding gradients; changing status/brand colours; adding a dependency.
- Never: change permissions/roles/nav_config; touch existing business rows; weaken CONSTRAINTS.md; commit/push/deploy without John's word; drop the column when any user has a non-default palette (down script guard).

## 8. Success criteria
All 9 acceptance criteria pass; `php scripts/check.php task` and `full` green; migration up/re-run/down/up clean on scratch and localhost; 0 non-HR rendering differences; John approves the look in the browser.

## 9. Open questions
- Q1 Should Admin (HRIS_ADMIN/SUPER_ADMIN) also see the palettes? Default: no (HR portal only).
- Q2 Palette names: "Soft Beige", "Earth Tones", "Soft Pastel", OK?
- Q3 Confirm the hex values are close to the look John wants (adjust after first browser screenshots).
