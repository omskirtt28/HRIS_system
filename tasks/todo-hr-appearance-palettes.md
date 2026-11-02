# Todo: HR appearance palettes

Plan: `plan-hr-appearance-palettes.md` | Spec: `../SPEC-hr-appearance-palettes.md` | Status: Tasks 1-4 done (migration 027 applied to localhost); Task 5 next

## Task 1: Column + UserPalette service
**Description:** Migration 027 adds `users.ui_palette` (default `light`) with a guarded down script. `UserPalette` has `KEYS`, `isValid()`, `canChoose(array $user)` (role_portal === 'hr'), `save(int $userId, string $key)`.
**Acceptance criteria:**
- [x] Column exists on scratch DB; up, re-run, down, up all clean; down refuses when any user has a non-`light` value
- [x] `isValid` accepts only light|dark|beige|earth|pastel; `canChoose` true only for the HR portal role
- [x] `save` writes one row and returns false for invalid input
**Verification:**
- [x] New `tests/hr_palette_test.php` red first, then green
- [x] mysqldump + restore check; scratch cycle; `php scripts/check.php task`
**Dependencies:** None
**Files likely touched:** `database/migrations/20261102_027_user_palette.sql`, `.down.sql`, `app/UserPalette.php`, `tests/hr_palette_test.php`, `scripts/check.php` (floor if needed)
**Estimated scope:** Medium

## Task 2: Load into session + `set_palette` POST
**Description:** Login puts `ui_palette` in the session user. New `set_palette` action in `index.php` (verify_csrf, logged in, `canChoose`, whitelist) saves and refreshes the session; returns JSON `{ok}` for fetch and redirects back otherwise.
**Acceptance criteria:**
- [x] HR user saves a valid key; session shows it on the next request
- [x] Non-HR user, unknown key, missing CSRF: nothing saved, error response
- [x] Apply to localhost `pmbsi_hris` only after all checks pass (standing workflow); no existing business rows changed
**Verification:**
- [x] Extend `tests/hr_palette_test.php` (handler logic) and an HTTP test on scratch DB
- [x] `php -l app/Auth.php index.php`; `php scripts/check.php task`
**Dependencies:** Task 1
**Files likely touched:** `app/Auth.php` (session array only), `index.php`, `tests/hr_palette_test.php`
**Estimated scope:** Small

## Checkpoint: After Task 2
- [ ] Tests green, migration applied locally per standing rule
- [ ] Review with John (nothing visible yet)

## Task 3: Palette CSS + contrast test
**Description:** `public/assets/palettes.css` with `:root[data-palette="beige|earth|pastel"]` blocks overriding the `--hris-*` and legacy app.css tokens (bg, surface, surface-2, line, text, muted, brand-soft, sidebar, sidebar-soft, plus `--panel/--border/--ink/--slate` aliases). Status and brand fills untouched.
**Acceptance criteria:**
- [x] Spec table values used; text and muted >= 4.5:1 on bg, surface, surface-2; sidebar text >= 4.5:1; test fails if below
- [x] Light and Dark unchanged (no rule outside `data-palette`)
**Verification:**
- [x] Contrast assertions added to `tests/hr_palette_test.php` parsing the CSS file
- [x] `php scripts/check.php task`
**Dependencies:** None (can run before Task 2)
**Files likely touched:** `public/assets/palettes.css`, `tests/hr_palette_test.php`
**Estimated scope:** Small

## Task 4: Appearance menu, server render, JS
**Description:** `render_head` writes `data-theme`/`data-palette` from the session value for HR and skips the localStorage script for HR. For HR the Appearance button opens a 5-option list (swatch, label, check, `aria-haspopup`, radio items, Arrow/Enter/Esc); choosing calls `setPalette()` (apply instantly, POST, revert + toast on failure). Non-HR keeps `toggleTheme()`.
**Acceptance criteria:**
- [x] HR sees 5 options, active one checked; employee/manager/admin see none and still toggle Light/Dark
- [x] Reload and new login keep the choice; no flash of the wrong palette
- [x] Keyboard and screen-reader usable; not clipped at 320/768/1024/1440; English text; matches Design-System
**Verification:**
- [x] HTTP render test: HR page has options + `data-palette`; employee page has neither
- [x] `php -l app/View.php`; `node --check public/assets/hris-app.js`; `php scripts/check.php task`
**Dependencies:** Tasks 2 and 3
**Files likely touched:** `app/View.php`, `public/assets/hris-app.js`, `public/assets/palettes.css` (menu styles), `tests/employee_profile_http_test.php` (existing Appearance assertion must still pass)
**Estimated scope:** Medium

## Checkpoint: After Task 4
- [ ] Full flow works for HR; non-HR unchanged
- [ ] John eyeballs each palette in the browser before commit

## Task 5: Sweep, browser check, vault sync
**Description:** Grep HR pages for hard-coded colours that ignore tokens and fix small ones; list large ones. Browser check on scratch DB (`run-hris-system`): dashboard, directory, a form in each palette at 1440 and 375 px. Vault sync and `check.php full` with `HRIS_TEST_DB`.
**Acceptance criteria:**
- [ ] Fix sweep report (Fixed / Also fixed / Left alone / Not verified)
- [ ] Vault: Progress, Screens, Data-Model (027), Business-Rules, Decision-Log, Open-Questions, CHANGELOG, Session-Log, Design-System palette table
- [ ] Floors ratcheted, none lowered
**Verification:**
- [ ] `HRIS_TEST_DB=<scratch> php scripts/check.php full`
**Dependencies:** Task 4
**Files likely touched:** vault notes, small CSS fixes, `scripts/check.php`
**Estimated scope:** Medium

Never without John's word: git push, deploy, commit.
