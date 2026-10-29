---
title: Session Log
type: log
status: active
tags: [log, sessions]
updated: 2026-10-26
---

# Session Log

## 2026-10-29 - Shared shell search, sidebar scroll and unread badges
- Implemented John's supplied plan using hris-patch, hris-ui and Ponytail; no subagents. Preserved existing uncommitted edits, including the hris-modern.css asset version in View.php.
- App files: app/View.php, app/NotificationService.php, app/NotificationModule.php, public/assets/hris-app.js, public/assets/notifications.css. Added tests/notification_sidebar_test.php (three mapping scenarios, syntax checked only, unexecuted).
- Removed shared top search; added user/portal scroll persistence, metadata/access-based all-unread single-menu counts, zero/99+/accessible badges and successful-read-only bell/sidebar updates. Stale GET responses are ignored; malformed read IDs rejected. Sidebar navigation does not mark read.
- PHP/JS syntax and three source-only scenarios passed: storage/search; mapping/query/response consistency; confirmed reads/auth/CSRF/failures. Same-pattern sweep found no other shared search markup/read callers needing changes. Existing unused top-search CSS left alone to avoid unrelated edits. Application/test files use CRLF; vault notes retain their existing endings. Module/design/model/progress/CHANGELOG synced.
- No browser/app/DB execution, migration, new notification recipients/events, commit or deployment. Actual scroll/rendering/live counts/query performance and regression execution unverified.

## 2026-10-29 - Incident case compact panel
- Status action stays visible; status note and separate HR-only note are collapsible. People, summary, attachments and timeline use compact disclosures, with counts and latest visible event preview. Existing access and actions retained.
- Static review and PHP syntax checks only; browser, mobile rendering and live behavior not verified. No database or deployment changes.

## 2026-10-27 - Incident Others submit blocker fixed
- **John:** screenshot showed "Nature other is missing or invalid (maximum 200 characters)"; report needs to send without that blocker. Planner traced a required Others field with an optional-looking UI, not a 200-character minimum.
- **Patched:** IncidentDocument nature_other optional, blank/short allowed, max5000; IncidentViews labels/help/maxlength match. Same-class supporting_other limit raised to5000; still required only when Supporting Documents Others selected. Category/auth/storage/transactions unchanged.
- **Checks:** php -l passed both changed PHP files. No JS changes. Further review/tester/simplify skipped per John's instruction; no runtime/browser/DB verification, commit or deployment. User performs submit acceptance. Specs/decision/plan synced.

## 2026-10-27 - Dashboard cleanup and template-based IR (source patched; John checks)
- **Approved:** [[2026-10-27-dashboard-incident-template]], BR-784..788. Exact master found in Downloads; unchanged source copy/hash owned by backend. Period picker/Download Report remain separate.
- **Implemented:** dashboard/status/viewer UI, six-section form and document card; viewer focus before pagination; frozen filer answers/exact-master generation, rollback cleanup/idempotence, case redirect, renewed document GET gates and versioned/CSRF-protected handler PDF retry. Generated documents use validated storage outside served roots, default `C:/xampp/hris-private/incident_documents`. Configure `incident_documents.libreoffice_path` in local config for optional PDF conversion; example file only changed, actual local config untouched.
- **Actual checks:** first QA PHP lint 12/13 passed, dashboard missing `?>` failed; all three source-extracted scripts passed `node --check`. Master SHA-256/160 anchors and changed-text CRLF/scoped whitespace checked. Round 1 restored dashboard tag, externalized storage after finding PHP built-in server ignores `.htaccess`, and rethrew PDO from ownEmployee to safe outer handler. **John then instructed "wag na mag review ... ako na mag check" and "need ko ma tapos na yan": active tester interrupted; further review/recheck and simplify skipped.** Final fixes not lint-reverified; authenticated/runtime/browser/DB/converter/rendered fidelity unverified. No commit/push/deployment.
- **Found left alone:** existing COE/generic attachment storage uses the older project-storage/Apache-deny pattern; separate authorization needed to address all supported servers. Current patch fixes only new IR document storage. John performs acceptance; no additional code review pending in this session.
- **Ownership:** backend = IncidentDocument, IncidentModule, TicketIncident, TicketService, TicketTypes, TicketViewerModule/Service, example config, immutable resources template; UX = HrDashboardViews, TicketViewerViews, IncidentViews, IncidentCaseViews, TicketViews cache bump, hr-ops.css/tickets.css. Preserve pre-existing agent/config/vault/.obsidian edits.
- **Usage fallback:** John authorized Claude Code handoff when Codex usage is exhausted. No takeover yet; global `.codex/AGENTS.md` and `.claude/CLAUDE.md` plus Conventions/Decision-Log/Start-Here updated. Continue from this checkpoint/current diff; original static-only restrictions persist.

## 2026-10-27 - Codex subagents + skills (John)
- **Desisyon:** HRIS planner -> senior backend/UI -> patch-tester -> simplify proposals; main coordinates and updates Obsidian kada patch/desisyon. Researcher as needed.
- **Setup:** `.codex/agents/hris-*.toml`, four `.agents/skills/` skills, AGENTS/CLAUDE routing and [[Codex-Agent-Workflow]]. Existing Claude pipeline and historical Codex-First withdrawal preserved.
- **Checks:** 6 TOMLs parsed; 4 skill headers/names/references checked; normalized new-file CRLF. Bundled validator unavailable (no PyYAML); independent source review used general subagents, not newly loaded roles. No runtime/browser/DB tests, commit or deployment; fresh-session discovery unverified.


Isang entry kada session, pinakabago sa taas. Mga plano ay nasa `Plans/` (template: [[_Template]]). Slice status ay nasa [[Progress]].

## 2026-10-26 - Codex-first sa bawat prompt (John)
- **Rule:** bawat prompt Codex muna via `codex exec`, kasama ang tanong, research, plano, build, config/docs/vault; Claude guides, nagre-review (`git diff --stat`, `patch-tester`), nagtutuwid at nag-uulat. Trivial chat lang ang direct answer; takeover kailangan ng `AskUserQuestion` at oo ni John.
- **Setup:** naitala ang AGENTS.md, `simplify-gatekeeper` (proposals lang), at session-start rule (Claude-Code-Brief muna, latest Session-Log at Progress). Rule/docs update lang sa apat na pinangalanang files; walang agent/config edit o commit. Hub: [[Codex-First]].

## 2026-10-26 - Vault audit (executed)
- **Ginawa:** in-audit ang vault laban sa I1-I4, Q62 at attendance export. Kulang lang ang attendance export: idinagdag ang BR-783 (Business-Rules), "As built" sa Attendance-and-DTR, at Q63 (HO Creatives/MIS biometric file) sa Open-Questions. Docs lang; walang code, migration o DB.
- **Susunod:** sagutin ang Q63; ATM row sa hr_dashboard test (dati nang bagsak).

## 2026-10-26 - I4 queue, Q62, attendance tests (executed)
- **Napagdesisyunan (John):** Q61 kumpirmado (HR dismiss lang, pangalan ng HR nakikita); Q62 gagawin (darker light tokens, hiwalay na commit). Tapos na ang attendance export fix (`2fc3e71`), tests na lang ang kulang.
- **Plano:** [[2026-10-26-i4-hr-incident-cases]]: 4 patches, local commits, walang push.
- **Patch 1 (I4 queue) done:** tabs, filters, aging, count parity sa dashboard (BR-782). Tests pasado; ATM row sa hr_dashboard ay dati nang bagsak. Light contrast bukas pa para sa Patch 2.
- **Patch 2 (Q62) done:** darker light tokens + `--hris-muted`; dark hindi nagbago (0 diffs sa 5,443 elements). Sinukat sa browser: lahat ng Q62-token text >= 4.84; muted/sidebar kinompyut ang ratio (hindi na-rebrowse).
- **Patch 3 done:** `attendance_export_review_test.php` 49/49 (rematch, currentReport, labels, source summary); walang app change. **Patch 4 done:** na-commit ang CLAUDE.md, Conventions at `.claude/agents/`; Progress may I1-I4 na. Lahat local commits, walang push.
- **Susunod:** John mag-check/polish; payroll backlog (PayrollCalculator tests, index.php thin-out) parked pa.

## 2026-10-26 - I3 leftovers patch (executed)
- **Ginawa:** dark-mode contrast ng chips, pills, badges, alerts at HR queue notes (CSS lang, dark-only tints); `peopleOnCase` 1 query na at de-duplicated; light gray chip, crumb at eyebrow mas readable.
- **Tests:** ticket_core 97, services 94, incident_case 111, incident_ui 38, incident_case_ui 40, incident_browser 44, hr_ops_browser 13 (isolated DB). Dark contrast sinukat sa browser: >= 6.2:1.
- **Hindi ginawa:** darker light-theme tokens (4.1-4.4:1 pa) - desisyon ni John (Q62); Q61 bukas pa.
- **Susunod:** I4 HR Incident Cases at polish.

## 2026-10-26 - Agent pipeline (docs/agents only)
- **Ginawa:** CLAUDE.md "Agent pipeline" (planner -> backend-engineer + ux-designer -> patch-tester, max 2 fix rounds). Bagong `planner` agent. Muling isinulat ang 4 na agents (dati mga kopya ng ORDESK na tumuturo sa ORDESK-Vault) para tumuro sa HRIS-Vault. Conventions at Decision-Log updated. Walang code, migration, o database na ginalaw.
- **Susunod:** subukan ang isang maliit na UI fix at tingnan kung dumaan sa planner at tester. Kapag "plan" ang sinabi, dapat isulat sa Plans/ at huminto.

## 2026-10-26 - Fix sweep rule (docs only)
- **Ginawa:** CLAUDE.md "Fix sweep", Conventions, Decision-Log, at sweep section sa agents (ux-designer, backend-engineer, patch-tester). Walang code, migration, o database na ginalaw.
- **Susunod:** subukan: magturo ng isang UI issue at tingnan kung nag-sweep ang agent.

## 2026-10-25 - Incident Report module I3 (executed)
- **Ginawa:** Case page ng Incident Report: header na may 5-hakbang na stepper, thread (HR update at HR-only internal note), participants rail, summary, attachments, timeline, sticky composer, HR panel (status na may remarks, magdagdag/magtanggal ng tao, internal note, reopen) at withdraw ng reporter. Bagong `incident-act` POST at `incident-people?case=` para sa handler lang. Walang migration; walang ginalaw sa `pmbsi_hris`.
- **Napagdesisyunan:** pangalan ng HR ay nakikita ng lahat ng readers (tulad ng sa messages); role labels, access wording, internal notes ay handler lang at wala sa markup ng iba. Hindi puwedeng mag-cancel ang HR, mag-dismiss lang (Q61).
- **Tests:** `incident_case_ui_test` 40/40, `incident_case_test` 111, `incident_ui_test` 38, ticket suites, browser 44/44 (403 matrix, forged POSTs, screenshots), `hr_ops_browser` 13/13. Local commit lang, walang push.
- **Susunod:** I4 HR Incident Cases at polish.

## 2026-10-25 - Incident Report module I3 (plan)
- **Napagdesisyunan:** I3 ay Case page (thread, participants rail, HR panel) gamit ang umiiral na service methods; walang pagbabago sa access rule; magsisimula lang pagkatapos ma-commit ang I2 (tumatakbo sa hiwalay na PowerShell ni John).
- **Ginawa:** idinagdag ang I3 scope sa [[2026-10-25-incident-report-module]]. Walang code na binago.
- **Susunod:** i-run ang I2, tapos ang I3 execute prompt.

## 2026-10-25 - Incident Report module I2 (executed)
- **Ginawa:** sidebar group Incident Reports, My Incidents, File an Incident (may people picker), JSON na people search, `core_incident_create`, anim na kategorya, inalis ang IR sa File an HR Request at My HR Requests, at may redirect ang mga lumang link. Walang migration; walang ginalaw sa `pmbsi_hris`.
- **Nakita:** tama ang I1 check: lahat ng role na puwedeng mag-file ay may `ticket.file.incident`. Ang tunay na hadlang ay ang linked Employee 201 record (12 active accounts ang wala), kaya may malinaw na mensahe na sa form. Sinara ang client-portal accounts sa picker pagkatapos ng code review.
- **Tests:** `incident_ui_test` 38/38, browser 25/25 (Reporter, CC, Involved, HR, outsider; light/dark; 1440/390), `incident_case_test` 111/111, 16 ticket suites at `hr_ops_browser` 13/13 pasado.
- **Open:** Q60 (accounts na walang employee record). **Susunod:** I3 Case page (thread, participants rail, stepper, HR panel). Local commit lang, walang push.

## 2026-10-25 - Incident Report module I2 (plan)
- **Napagdesisyunan (John delegated):** 6 kategorya (may Harassment or discrimination), walang draft sa I2, ang `hr-incidents` ay nananatili para sa HR hanggang I4.
- **Nakita (read-only):** lahat ng role na puwedeng mag-file ay may `ticket.file.incident` na, kaya walang grant migration. Q60 ay employee-record link na lang.
- **Ginawa:** idinagdag ang I2 scope sa [[2026-10-25-incident-report-module]]. Walang code na binago.
- **Susunod:** patakbuhin ang I2 execute prompt.

## 2026-10-25 - Incident Report module I1 (executed)
- **Ginawa:** migration 017 (`incident_details`, `incident_participants`, 4 bagong status), `TicketIncident` na may isang read rule, HR status flow, add/remove ng participants, notifications na walang private text, at `incident_case_test` (access matrix, 111/111). Na-apply na sa `pmbsi_hris` pagkatapos ng verified dump at up/re-run/down/up; 7 tickets walang nagbago, 0 IR ang laman dati.
- **Pinili ko (ipapakita kay John):** ang Involved/Witness na pinili ng nag-file ay hindi nakakabasa hangga't hindi isinasama ni HR; HR na kasama sa case ay hindi na handler; sensitive.view ay hindi na gate. Nakasulat sa Decision-Log at Q56-Q60.
- **Open:** Q59 retention/confidentiality (NEEDS VERIFICATION), Q60 sino ang puwedeng mag-file (`ticket.file.incident` at walang employee record). Wala pang screen kaya hindi pa makapagpalit ng status si HR sa UI hanggang I3.
- **Susunod:** I2 Employee UI (isang phase kada session). Local commit lang, walang push.

## 2026-10-25 - Incident Report module (plan)
- **Napagdesisyunan (John):** lahat ng user ay puwedeng mag-file; nakakabasa lang ang nag-file, HR, mga isinama ni HR at mga CC na pinili ng nag-file; 5 hakbang na status na imamarka ni HR; tatanggalin ang IR sa File an HR Request at gagawing hiwalay na module.
- **Pinili ko:** nananatili sa ticket engine, may bagong `incident_details` at `incident_participants`, thread na may HR-only internal note, 4 na phase (I1-I4).
- **Ginawa:** [[2026-10-25-incident-report-module]] (approved, may execute prompt). Walang code na binago.
- **Open:** retention at confidentiality ng incident records (NEEDS VERIFICATION); kung makakabasa pa ang CC na tinanggal ng HR.

## 2026-10-25 - TA form existing logs (executed)
- **Ginawa:** na-reproduce ang bug sa isolated DB (1 at 2 ambiguous punches = null sa `requestTimes()`, pero may placement sa Check Attendance), tapos shared `placeAmbiguousForDisplay()` para pareho ang existing logs; form, context JSON at submit guard ay sumusunod. Walang migration, walang sulat sa `attendance_daily`, hindi ginalaw ang `PayrollCalculator::assign`. Tests: `ta_existing_logs_test` 19/19, browser 8/8, screenshots before/after; fresh-DB suites pasado.
- **Open:** Q55 (5:08 PM sa Break Out o Time Out?). Hindi nag-apply sa `pmbsi_hris` dahil walang DB change; local commit lang, walang push.

## 2026-10-25 - TA form existing logs (plan)
- **Nakita:** sa File TA form, "Missing" lahat ang existing logs at blangko ang apat na input, kahit may 12:17 PM at 5:08 PM sa Check Attendance (Oct 12). Sanhi (mula sa code, hindi pa nasubukan): `requestTimes()` ay `null` ang binabalik kapag ambiguous ang punches, samantalang display-only na slot placement ang gamit ng Check Attendance.
- **Ginawa:** [[2026-10-25-ta-form-existing-logs]] (approved, may execute prompt) at [[Cutoff-Checklist]] para sa bawat bagong cutoff. Walang code na binago.
- **Open:** 5:08 PM ay nakalagay sa Break Out; baka Time Out. Tanong kay John bago baguhin ang placement rule.
- **Susunod:** patakbuhin ang execute prompt sa plano (hiwalay sa ticketing P2).

## 2026-10-25 - Ticketing redesign P1 Foundation (executed)
- **Ginawa:** shared components sa `tickets.css`, date/label helpers, stepper fix ("skipped" na dash, hindi "3"), cache-buster `-17`. Views at CSS lang; walang migration, hindi ginalaw ang live DB.
- **Nakita:** unang test run ay may regression: ang timeline ay naglabas ng HR response (step remarks) sa view-only user. Inayos: remarks at pangalan ng HR ay opt-in. Dalawang test (COE, hr_queue_bulk) ay nag-fail lang sa reused DB; pasado sa fresh DB.
- **Susunod:** P2 Detail page (dalawang column, Payroll Concern sa parehong layout). Local commit lang, walang push.

## 2026-10-25 - Ticketing UI/UX redesign plan
- **Napagdesisyunan:** si Claude ang pumili ng design (John delegated): dalawang column na detail page, isang layout para sa lahat ng ticket types, sakop ang lahat ng ticket screens sa 5 phases (P1-P5), isang phase kada session. Views at CSS lang.
- **Nakita:** luma ang Payroll Concern detail page; stepper bug (skipped In Progress ay nagpapakita ng "3"); raw status at timestamps; hindi pare-pareho ang spacing.
- **Ginawa:** [[2026-10-25-ticketing-ui-ux-redesign]] (approved, may execute prompt). Walang code na binago.
- **Susunod:** patakbuhin ang P1 gamit ang execute prompt sa plano.

## 2026-10-25 - Auto-log setup
- **Napagdesisyunan:** ang vault ang memorya ng mga plano at session; kapag nag-"plan" si John, isusulat ang plano sa `Plans/` at ililista dito.
- **Ginawa:** `Plans/_Template.md`, ang note na ito, at bagong rule sa CLAUDE.md ("Plan and session log").
- **Susunod:** gamitin ang rule sa susunod na "plan".

## 2026-10-27 - BINAWI ang Codex muna (John)
- BINAWI 2026-10-27: Claude Code + sariling subagents + advisor na ulit; walang codex exec maliban kung hilingin ni John.

## 2026-10-28 - Employee work company
- Source patch: 2LOG / PMBSI work assignment in Add and Edit, conditional fields and 2LOG HR position filtering; legacy records remain unassigned until HR selects. PMBSI remains legal employer.
- Additive migration and guarded rollback prepared; not applied. PHP/JS syntax checked; runtime, database and browser acceptance not verified. No deployment or commit.


## 2026-10-28 - PMBSI positions limited to Human Resource
- John: existing other positions belong to 2LOG; PMBSI gets Human Resource only until company confirms more. Planner -> engineer -> static tester -> simplify reviewer; main owns vault sync.
- Incremental source patch: app/EmployeeRepository.php shared eligibility validation and index.php Add/Edit option filtering/messages. Preserve prior work-company patch and migration; no DB/master-data changes, commit or deployment. Unknown active HR master availability is shown as an empty-state message, never a fake position.
- Verification: PHP lint passed EmployeeRepository.php and index.php; both affected inline scripts passed node --check. Static QA PASS WITH NOTES, three source scenarios reviewed. Runtime/browser/DB and active HR master availability not verified.
- Simplify review: no blocker. Optional misplaced docblock correction and shared inline-script renderer left as proposals; no extra refactor. Recruitment/import flows without work_company remain outside scope. Skills: hris-patch, hris-debug, hris-ui, hris-review.

## 2026-10-28 - Obsidian-only and minimal agent context
Recorded John's Obsidian-only and minimal agent context instruction in workflow, Conventions and Decision-Log, linked from Start-Here. No agents spawned; vault documentation only.

- 2026-10-28: John clarified: skills follow the same Obsidian-only scope as main and agents. Use only the relevant vault note/section and reuse session context. Do not open external SKILL.md files, auto-run skill workflows, spawn agents or expand reads beyond Obsidian unless John authorizes that scope. See [[Codex-Agent-Workflow]].

## 2026-10-28 - Token budget for agents
John: context ran out from subagents rereading code/vault. Added READ BUDGET to all agents, CLAUDE.md Token budget, and [[Claude-Agents-and-Skills]]. Files: .claude/agents/*, CLAUDE.md, 00-Meta/Claude-Agents-and-Skills.md, Start-Here.md.

## 2026-10-28 - Work-company patch, Claude handoff (Codex usage limit)
- Tinuloy ni Claude ang Codex patch (John approved ang handoff + `.claude/allow-claude-edit` para sa hook). Cleanup: tinanggal ang stray `.work_company_ui_*.py`, inayos ang indent sa Edit form, inilipat ang naligaw na docblock ng `resolveCompany`.
- Tests (isolated `hris_slice15_test_20261028_234641`, galing sa verified mysqldump): migration up, re-run, down, up OK; down guard tumanggi kapag may assignment; live `pmbsi_hris` walang `work_company` (hindi in-apply). Validation (PMBSI/2LOG/invalid/required) at alias helper 9/9 OK. PHP lint OK.
- **Open:** ang tanging active HR-type position sa data ay "Human Resources Assistant"; hindi ito exact alias kaya PMBSI = empty-state at 2LOG ay nag-e-exclude. Tanong kay John: idagdag ba bilang PMBSI position? Browser check at apply sa live DB hindi pa ginawa. Walang commit/push.

- Applied to localhost pmbsi_hris (John: "patch mo na"): `employees.work_company VARCHAR(10) NULL`. Basis: verified dump before_stageb_118 (restore verified), isolated-DB migration cycle + repository update test OK. After: 370 employees, department/position counts unchanged (370/370), work_company all NULL (legacy unassigned). Browser check not done. No commit/push.

## 2026-10-28 - Organization Setup friendly add forms
- Code input removed from 6 master add forms; `FoundationRepository::uniqueCode` generates and de-dupes; duplicate names rejected; Retire/Reactivate labels; HR empty-state link in Employee Add/Edit. Plan: ~/.claude/plans/bale-bro-eto-gusto-curried-grove.md.
- Live in localhost working tree (no migration). php -l OK; browser/isolated-DB test not run. No commit/push. Next: John adds "Human Resource" position in Organization Setup.

## 2026-10-29 - IR handler: CC-only HR can handle
- Sanhi: HR Kristian Acosta naka-CC sa IR kaya excluded sa `TicketIncident::isHandler` (hindi dahil unlinked account). Fix: tanging reporter at Involved/Witness HR ang excluded; CC-only HR puwede na (BR-775 updated). Permission-rule change, aprubado ni John.
- `app/TicketIncident.php` only; php -l OK; isolated-DB test (tests/incident_case_test.php) hindi pa tinakbo. No migration, no commit/push.
- Verified (isolated DB hris_slice15_test_20261028_234641, scratch check): reporter/Involved/Witness HR = not handler; CC-only HR and unlisted HR = handler. Old `tests/incident_case_test.php` already fails before this patch ("Choose one nature of incident", stale fixture) - not touched.

## 2026-10-29 - IR case page cleanup (UI only)
- Removed "Include someone" picker from HR actions (people are chosen at filing; CC list in "People on this case" is the source). Status form is now always visible; the duplicate top "Update status" button and collapsed copy are gone. Internal note kept. Backend `core_incident_participant_add` untouched (no UI now).
- Composer no longer sticky (it covered the conversation). Original incident report now shows on the page right away: PDF iframe when a PDF exists, else a read-only HTML sheet of the frozen answers; Download (.docx)/Open PDF stay as header buttons; handler-only "Create PDF copy".
- Files: `app/IncidentCaseViews.php`, `public/assets/tickets.css`. No migration, no commit/push. Reader rule unchanged (reporter + eligible HR only).

## 2026-10-29 - John's rules in the vault for agents
- `00-Meta/Claude-Agents-and-Skills.md` now has "Tungkol kay John" (preferences, forbidden actions) and an agent role table; each `.claude/agents/*.md` points to it (one line). No app change.

## 2026-10-29 - Codex independence and lower usage
- John requested independent Codex/Claude agents and skills, shared patch facts in Obsidian, and Ponytail for Codex. Updated [[Codex-Agent-Workflow]], [[Conventions]], [[Decision-Log]], [[Start-Here]] and [[Progress]].
- Vault-only documentation update; actual agent/config separation and Ponytail installation/fresh-session discovery remain unverified. No app, data or deployment change; usage savings not measured.

- Ponytail verification: Codex session lists all six Ponytail skills; their SKILL.md files exist under the user's Codex skills directory, and the main skill header was read. Version and discovery in a new session remain unverified. Agent/config separation is still pending.

## 2026-10-29 - Employee linking and PMBSI position
- Employee forms now list eligible HR/Admin accounts for Super Admin, retaining recruitment's employee-only picker; server validates changed links and restricts HR/Admin and own-account changes. Users requires an explicit link/unlink selection; Employee audits include account IDs.
- Company position filter accepts Human Resource PMBSI variants. Human Resources Assistant remains excluded by the existing policy and needs a separate business decision.
- `app/EmployeeRepository.php`, `index.php`; PHP lint and diff check passed. No browser/runtime/DB testing or writes, deployment or commit. Plan: [[2026-10-29-employee-account-linking]].

## 2026-10-29 - Codex HRIS sandbox ACL repair
- Sandbox log reported access denied updating deny ACLs on HRIS .agents and .codex. Granted the John Joshua Windows account ChangePermissions on those folder roots only; existing ACL entries preserved. Non-elevated ACL write checks passed on both. A fresh sandboxed HRIS session must verify helper startup; no app or database change.

## 2026-10-29 - Global Codex Ponytail plugin
- John requested Ponytail across projects. Codex marketplace and plugin installed globally from DietrichGebert/ponytail, version 5.3.3; plugin list confirms installed and enabled. The six global Ponytail skills already existed. Node v24.13.0 available. Hook trust and fresh-session behavior remain unverified; no project application or Claude settings changed.

## 2026-10-29 - PMBSI departments (prepared)
- PMBSI Departments: Organization Setup add/retire/reactivate; Employee 201 Add/Edit filters and validates departments by work company and uses existing history. Existing departments default to 2LOG. PHP lint and two inline JS syntax checks passed; static QA PASS WITH NOTES. Roster import can still bypass company classification. Migration, DB, runtime and browser not tested; no deployment or commit.

## 2026-10-29 - Codex HRIS low-usage alignment
- Aligned project instructions, six Codex agents, four skills and vault routing with bounded task context and no automatic agent relay or Claude configuration reads. Existing runtime and security restrictions retained. See [[Codex-Agent-Workflow]]. Static checks only; fresh-session discovery and usage savings unverified.

- 2026-10-29 verification: Fresh local Codex app-server skills/list discovered all four HRIS skills; config/read found no HRIS agent registrations initially. Added project .codex/config.toml with six role entries; a fresh config/read returned all six. TOML and target references passed. No agent was spawned; behavior, model usage and savings remain unverified.

- 2026-10-29: Authorized isolated department migration checks passed (up/re-run/down/up, existing 2LOG, employee checksum, PMBSI insert, rollback guard). Backup contains HRIS data; see [[Data-Model]]. Local application remains separately unauthorized.

- 2026-10-29: John authorized and applied department work-company migration to localhost pmbsi_hris. Fresh private backup department_preapply_20261029_150231.sql restored to hris_department_verify_20261029_150231: 118 base-table checksums and schema dumps (excluding comments) matched. Post-apply: VARCHAR(10) NOT NULL DEFAULT 2LOG; 29 existing departments 2LOG; original department field mismatches 0; employee assignment mismatches 0 across 370 employees, employee checksum 1552809314 unchanged. PMBSI insert and guarded down were tested on the earlier isolated database only. Browser/application flow not tested; no commit or deployment.

- 2026-10-29: Organization Setup positions split by existing Employee Add/Edit eligibility into 2LOG, PMBSI and Needs classification. Company add validates name and optional active company department; shared records and eligibility unchanged, no schema or data migration. PHP lint passed; browser, application and DB behavior unverified.

- 2026-10-29: Organization Setup hides Needs classification and adds position default-department editing with company eligibility, active department validation, permission, CSRF and audit. Existing assignment and history data are unchanged; changing a default can affect later employee/recruitment validation. Human Resources Assistant removal remains blocked pending database reference verification; no DB access or write performed. PHP syntax checked; runtime and browser unverified.

- 2026-10-29: Organization Setup UI cleanup: scoped markup/CSS and status wording; existing actions unchanged. PHP lint passed; browser, application and DB unverified.

## 2026-10-29 - Organization Setup side-by-side UI/UX

- Implemented the supplied Organization Setup plan using hris-ui, hris-patch planning/delivery guidance and Ponytail; main only. Scoped markup/CSS and stylesheet version updated; all master cards swept. Checks (3 scenarios): static markup/labels/form contracts; responsive/focus/active/inactive/read-only source review; PHP lint plus task-entry diff and line-ending review. Both PHP files passed lint; no browser/app/DB execution. Source files were LF at entry; retained LF without whole-file normalization. Existing unrelated working-tree edits retained. Visual acceptance pending.

## 2026-10-29 - CEO patch report (October 20-29)
- John requested a Canva report of all documented HRIS work in the period. Source-checked root changelog, Progress, Session-Log, Stage B results and attendance note; consolidated dated work and latest localhost migration status. Oct21-23 has no dated record located; Oct12 export fix excluded, Oct26 regression tests included. Report content/checkpoint: [[2026-10-29-CEO-Patch-Report]]. Canva generation started; no application changes, runtime/browser/DB checks or deployment.
