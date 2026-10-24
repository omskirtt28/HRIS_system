---
name: run-hris-system
description: Run, start, drive, screenshot or test the PMBSI HRIS PHP app (XAMPP/MySQL) against an isolated scratch database with a real browser. Use when asked to run the app, see a page working, take a screenshot, run the ticket/HR browser checks, or verify a ticket, dashboard, COE or report change without touching the live pmbsi_hris database.
---

# Run the HRIS (PHP + MySQL, Windows/XAMPP)

Paths are relative to the repo root (`C:\xampp\htdocs\HRIS_system`). The live database `pmbsi_hris` is never used for driving or tests. Everything below runs against a throwaway copy `hris_slice15_test_YYYYMMDD_HHMMSS` that the migration runner restores from a verified mysqldump. The driver is the existing browser harness in `tests/` (a PHP test router plus a Playwright `.cjs` script using the Edge install); this skill only says how to start it.

## Prerequisites
- XAMPP MySQL running; PHP 8.2 CLI on PATH (`php -v`), `zip` and `fileinfo` enabled.
- Microsoft Edge at `C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe`.
- Playwright module under `~/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright` (the `.cjs` scripts require it from there).

## 1. Make a scratch database (agent path)
```bash
php scripts/ticket_stageb_database.php prepare 99     # backs up live, restores into a scratch DB, prints TEST_DATABASE=...
export HRIS_TEST_DB=hris_slice15_test_20261025_013323  # use the printed name
```
Use an unused number. If it says `FAIL Keep existing manifest`, `storage/slice15-private/stageb-99.json` already exists: rename it, never reuse it. To test a new migration `database/migrations/2026????_NNN_*.sql`, use that number instead of 99, then run `php scripts/ticket_stageb_database.php cycle NNN` (up, re-run, down, up on the scratch copy). `apply NNN` writes to the LIVE database, so only run it when the repo's test-then-apply rule says so.

## 2. Drive pages in a real browser
Pick the router that serves the pages you care about (all of them run the real handlers on the scratch DB):
```bash
php tests/coe_browser_setup.php                         # synthetic fixtures + sample .docx files, writes storage/slice15-private/coe-fixtures.json
(php -S 127.0.0.1:8898 tests/coe_browser_router.php > storage/slice15-private/coe-server.log 2>&1 &)
node tests/coe_browser.cjs                              # logs in as fixture users, checks 360/768/1280, light/dark, saves PNGs
```
Same pattern for the report (`tests/report_browser_setup.php`, `report_browser_router.php`, port 8899, `report_browser.cjs`) and the dashboard/queue (`hr_ops_browser_setup.php`, `hr_ops_browser_router.php`, port 8897, `hr_ops_browser.cjs`). Screenshots land in `storage/slice15-private/` (e.g. `report-1280.png`, `coe-preview-1280.png`); open one with the Read tool to actually look at it. Fixture logins use password `Slice15-Test-Only!9`; emails are in the fixtures JSON.

Run one `*_browser_setup.php` per scratch DB, and restart the server whenever `HRIS_TEST_DB` changes. Stop all test servers (PowerShell; filter on `php.exe`, a bare command-line match also kills bash wrappers):
```powershell
Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -match '-S 127\.0\.0\.1:889[789]' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
```
Verified 2026-10-25: report 10/10, COE 18/18, dashboard/queue 13/13 (each on its own fresh DB), `ticket_core_test` 97 passed, 0 failed.

## 3. PHP suites (no browser)
```bash
HRIS_TEST_DB=hris_slice15_test_... php tests/ticket_core_test.php     # prints RESULT N passed, M failed
```
A few older tests (`adl_reporting_test`, `directory_direct_card_test`, `attendance_slots_test`, `oc_head_office_sections_test`) print `N passed, M failed` without the `RESULT` word. Tests refuse to run unless `HRIS_TEST_DB` matches `hris_slice15_test_\d{8}_\d{6}`.

## Gotchas
- The test routers only serve `/index.php` and `/public/assets/*`, so every page logs a 404 for `favicon.ico`; the `.cjs` scripts filter it. The HR fixture user has no filing permission, so a router that redirects to `hr-requests-my` after login logs one 403 (filtered the same way).
- Test suites write generated files into the real `storage/` folders (e.g. `storage/coe_documents`, `storage/coe_templates`). Delete them afterwards; they are git-ignored but contain synthetic data.
- A `php -S` server keeps the `HRIS_TEST_DB` it started with, and Windows lets two servers bind the same port. A stale server from an earlier session answers on the port and every login silently fails (page stays on the login form, 401). Always run the stop command above and check `netstat -ano | grep -E ':889[789] .*LISTENING'` shows one PID per port before starting.
- Mixing setups on one scratch DB breaks later scripts: after `report_browser_setup` + `coe_browser_setup`, `hr_ops_browser.cjs` failed on `strict mode violation ... MANPOWER_REQUEST ... resolved to 2 elements`. Fresh `prepare` per browser script.
- Tests on a reused scratch DB accumulate rows (templates, settings, tickets); tests that need a clean slate delete their own tables first. Prefer a fresh `prepare` per patch.
- `ZipArchive::open($path, ZipArchive::OVERWRITE)` fails on a path that does not exist yet; use `CREATE|OVERWRITE`.
- Do not write `cat > "$TMPDIR/x"` in bash here: `$TMPDIR` is empty and it hangs on stdin. Use the Write tool or the scratchpad path.
- Files in this repo may be CRLF; exact-string edits that include a trailing newline can fail. Anchor on text without the line break.

## Troubleshooting
- `Set HRIS_TEST_DB to the isolated Slice-15 database.` -> export the exact `TEST_DATABASE=` value from `prepare`.
- `Invalid or uninitialized Zip object` in a setup script -> see the ZipArchive gotcha.
- Browser script `ENOENT ... fixtures.json` -> the matching `*_browser_setup.php` failed; run it alone and read its error.
- Browser script `locator.waitFor: Timeout` right after login (`RESULT 0 passed, 1 failed`) -> stale server on the port or wrong `HRIS_TEST_DB`; see the `php -S` gotcha.
- Page shows `Database setup required` -> you started `router.php` (live DB config) instead of a `tests/*_browser_router.php`.
