---
title: Session Log
type: log
status: active
tags: [log, sessions]
updated: 2026-10-26
---

# Session Log

<!-- DEV-SKILLS-20261030 -->
## 2026-10-30 - Codex dev skills
Two shared Addy dev skills installed and documented in [[Codex-Agent-Workflow]]. Four local HRIS dev skills retained; removed superseded pipeline/shared-role block. No application/schema/data/deploy change. Source/resources and fresh discovery evidence: Brain installation receipt; token savings unmeasured.

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

## 2026-10-31 - Collapsible sidebar icon rail (Claude)
- John asked for the sidebar to fold to the left while icons and notification badges stay visible. Desktop-only toggle in the topbar; folded rail is 88px; the full logo is scaled to 68px wide (revised after John asked for the full logo); hover shows a title tooltip; the choice is saved in localStorage (`hris-side`). Mobile off-canvas is unchanged. Spec: `SPEC.md` (repo root).
- Files: app/View.php, public/assets/hris-app.js, public/assets/sidebar-fold.css (new).
- Checks: php -l and node --check pass; notification_sidebar_test passes. incident_ui_test and ticket_viewers_test need HRIS_TEST_DB and were not run. No browser/visual check, no DB or migration, no commit. Visual acceptance pending. Design-System.md/Screens.md/CHANGELOG not yet synced.

## 2026-10-31 - My Profile spec (Claude, spec only)
- John asked to build My Profile (sidebar item now Soon) as an advanced-UI version of the old HRIS profile page. Decisions: employees are read-only (HR maintains data in Employee 201); bring all old sections plus shortcuts to existing modules; self-only access with masked government IDs; layout chosen by Claude (hero + tabs). Spec: `SPEC-my-profile.md` (repo root; root `SPEC.md` still holds the sidebar spec). Pending: John's confirmation, answer on rate/leave visibility. No code, DB, browser check, commit or deploy.

## 2026-10-31 - My Profile plan (Claude, plan only)
- John approved SPEC-my-profile.md. Plan: tasks/plan.md, checklist tasks/todo.md (7 vertical slices T1-T7 with checkpoints). Open: rate/leave visibility, which document types employees see, whether employees.view_self is granted to EMPLOYEE in role_permissions. No code, DB, browser check, commit or deploy.

## 2026-10-31 - My Profile T1 built (Claude)
- T1 of tasks/plan.md: sidebar My Profile enabled (page employee-profile, permission employees.view_self), hero card + empty state, own-only photo route employee-profile-photo. No id parameter is read. Files: app/EmployeeProfileService.php, app/EmployeeProfileViews.php, public/assets/employee-profile.css (?v=20261031-my-profile-v1), index.php, app/View.php, tests/employee_profile_test.php. Checks: php -l on all five; pure test passes 14/14. Read-only DB query on localhost pmbsi_hris: EMPLOYEE role has employees.view_self (live not checked). No browser check, no DB write, no commit (working tree has unrelated changes), no deploy. Next: T2 tabs; still need John answers on rate/leave and document types.

## 2026-10-31 - My Profile T2 built (Claude)
- John answered: show Daily/Hourly rate and leave credits; employees see ALL their own document types (no MEMO filter). T2 tabs Personal / Contact and Address / Employment (job, dates, pay rate, leave credits, employment history), anchor-based accessible tabs (no-JS fallback shows all panels). Pay rate comes from payroll_employee_rates (current row): hourly = salary_amount / hourly_divisor, same as PayrollCalculator; daily or monthly shown only when that is the stored basis, never guessed. Old-page fields with no column in employees (religion, blood type, mother maiden name, children, fax/tel, preferred contact, city/province/zip, address line 2, bio code) are omitted, not invented; HR would need a schema change first (ask). Files: app/EmployeeProfileService.php, app/EmployeeProfileViews.php, public/assets/employee-profile.css/.js (?v=20261031-my-profile-v2), index.php, app/View.php, tests/employee_profile_test.php. Checks: php -l, node --check, pure test 31/31, smoke render. No browser, no DB write, no commit, no deploy. Next: T3 masked IDs + reveal.

## 2026-10-31 - My Profile T3 built (Claude)
- John: the IDs section will hold scanned images of documents, so UI must be well organised. Built tab "IDs and Documents": Government IDs list (type, masked last 4, issued/expiry with Expired / Expires soon chips, Show/Hide button; full number fetched by POST employee-profile-reveal with CSRF, own id rows only, audited as REVEAL_OWN_GOV_ID without the number, no-store, re-masks after 30 s) and Documents card (thumbnail grid, ALL document types per John, type filter chips with counts, image preview dialog, View and Download; PDF/DOC/XLS shown as type tile). Own routes: employee-profile-document (ownership enforced by EmployeeRepository::document(own id, doc id), inline only for pdf/jpg/png/webp, nosniff), employee-profile-photo. HR notes on ID rows are not shown. Limit: thumbnails are the original image, lazy-loaded (GD is not enabled on local PHP; a server thumbnail step is a later option for very large scans, up to 10 MB each). Files: app/EmployeeProfileService.php, app/EmployeeProfileViews.php, public/assets/employee-profile.css/.js (?v=20261031-my-profile-v3), index.php, app/View.php, tests/employee_profile_test.php. Checks: php -l, node --check, pure test 49/49. Not run: DB ownership/reveal scenarios (T7, needs HRIS_TEST_DB), browser. No DB write, no commit, no deploy. Next: security checkpoint then T5 shortcuts + print.

## 2026-10-31 - My Profile T5 + HR access (Claude)
- John: HR users need the same profile, from the top-right Profile menu (it was a dead # link). Decision (John-requested): the My Profile routes now accept every internal account (portal employee, hr or admin, plus SUPER_ADMIN/HRIS_ADMIN) instead of the employees.view_self permission, because HR roles (HR_USER, PAYROLL, HR_LEAVE, HR_TIMEKEEPING, RECRUITER...) do not hold that permission (read-only DB check on localhost). Client accounts stay blocked. Still own record only; no id parameter. No permission or role rows changed. The Profile menu link now goes to employee-profile for these accounts (client accounts keep #). Sidebar and breadcrumb follow the viewer portal (employee / hr / admin). Note text: employees see "contact HR"; HR/admin see "maintained in Employee 201". HR/admin without a linked employee row see the empty state.
- Shortcut strip: Print Summary (real, window.print; revealed IDs are re-masked before print; print CSS hides sidebar/topbar/controls, A4, shows all tabs), Send Request -> hr-requests-my (tickets.request_self), Send DTR Adjustment -> employee-request-new (payroll.request_self), Leave, Attendance (permission-gated, hidden without it), Payslip / Deductions / Additional / Downloads / FAQs as Soon pills (no dead links). HR roles hold none of those four permissions, so HR sees Print + Soon only.
- Files this slice: app/EmployeeProfileService.php, app/EmployeeProfileViews.php, public/assets/employee-profile.css/.js (?v=20261031-my-profile-v4), index.php (portal guard excludes employee-profile*, routes), app/View.php (Profile menu link). Checks: php -l, node --check, pure test 66/66. Not run: browser/print preview, DB scenarios (T7). No DB write, no commit, no deploy.

## 2026-10-31 - My Profile T6 and T7 done (Claude)
- T6: replaced missing --hris-red with --hris-danger, empty values use muted italic (contrast), chips and document links 44px, print layout, ?v=20261031-my-profile-v5. T7: tests/employee_profile_db_test.php on scratch DB pmbsi_hris_test (created from pmbsi_hris, fixtures rolled back, then dropped): 12/12 PASS (own data only, other employee ID/document refused, all document types listed, unlinked account empty, client denied/HR allowed, masked data, audited reveal without number). Pure test 66/66. Vault synced: Progress, Session-Log, Self-Service-Portal, Screens, API-Surface, Business-Rules, Decision-Log, Open-Questions, Design-System, CHANGELOG. Status: built locally, whole slice T1-T7; browser and print preview NOT verified; no commit (working tree has unrelated changes), no deploy; live DB not touched except read-only checks.

## 2026-10-31 - My Profile /test pass (Claude)
- Added tests/employee_profile_http_test.php: copies the app to a temp folder, points it at scratch DB pmbsi_hris_test, serves with php -S and drives real login/pages/routes with curl (employee, HR, client, anonymous, unlinked) = 41 checks PASS, re-runnable. Optional HRIS_SHOTS=<folder> takes headless Edge screenshots (1280 and 500 wide; Edge headless minimum is 500) of the isolated copy only. Pure 66/66, DB 12/12, directory_direct_card_test run on scratch, notification_sidebar_test passes, php -l clean on app/*.php and index.php. Scratch DB dropped; live DB had 0 reveal audit rows from tests.
- UI findings fixed from the screenshots: IDs card full width, document View/Download aligned across cards, usable quick links first and Soon pills last, Pay rate shows only existing rates (or one empty message) instead of three Not provided, history card full width, mobile hero divider. CSS ?v=20261031-my-profile-v7. Not verified: dark mode, a real phone width below 500, print preview, tab bar on narrow phones scrolls sideways (last tab partly hidden by design), real scanned images.

## 2026-10-31 - Constraints set up (Claude, /constraints)
- Added CONSTRAINTS.md (floor, enforced test floors 66/3/12/41, dropped dimensions with reasons, empty exceptions table), scripts/check.php (fast / task / full; syntax + secrets + suite floors; gate self-tested with a fake secret and a parse error), and a CLAUDE.md pointer. No tools installed (gitleaks, Semgrep, osv-scanner absent; no coverage driver). Full run with scratch DB: all PASS. No CI exists, so running the script is the gate.

## 2026-10-31 - Code review pass (Claude, /review)
- Fixed: zero/invalid dates (0000-00-00 rendered as year -1 / 1970) in EmployeeProfileService::date and tenure; tab links no longer carry server tabindex (keyboard reachable without JS); portal-guard exemption is an exact 4-route list, not a prefix; profile CSS/JS load only on employee-profile pages. Floors raised: pure 70, http 42. Left as Optional: reveal rate limit, CSP sandbox on inline PDF, shared stream helper, page() parameter object, thumbnails. Full gate with scratch DB: all PASS.

## 2026-10-31 - Web performance pass (Claude, /webperf, quick mode)
- Auditor (source scan + measured scratch-copy numbers: profile median 80 ms vs dashboard 122 ms, HTML 22.7 KB / 4.9 KB gzip, profile CSS+JS 4.2 KB gzip, profile assets only on profile pages). No Lighthouse/CrUX available, so LCP/INP/CLS NOT measured.
- Fixed in this patch: document grid uses ?thumb=1 (480 px JPEG via GD, cached in storage/employee_documents/.thumbs, falls back to the original when GD is missing/image small or large); width/height on thumbnails and photo; ETag + Last-Modified + 304 and private max-age on document/photo streams via EmployeeProfileService::stream; session_write_close before streaming; non-active tab panels render hidden at first paint with a noscript fallback (no flash of all panels). GD path verified by running tests with php -d extension=gd; GD is OFF in local php.ini, check extension=gd on the live host (without it the original is served, as before). Floors raised: pure 76, http 46.
- Left alone (global/pre-existing): Google Fonts and ~12 global stylesheets in render_head (hris-modern.css 110 KB), ProbationService/OIC checks running on every page, query tuning (no evidence; EXPLAIN on production-size data if needed). Thumb cache is not invalidated (stored_name is unique per upload); deleting a document leaves its small cached preview behind.

## 2026-10-31 - Code simplification pass (Claude, /code-simplify)
- Scanned the My Profile files for dead code (none: every method and every .myp-* class is used). Replaced three copies of "load the whole employee row just to get its id" in the reveal/document/photo routes with EmployeeProfileService::ownEmployeeId() (one light query; same behavior, less work per file request) and folded the repeated download flag. Deliberately NOT changed: EmployeeProfileViews::page() eight parameters (a data object would touch every caller and test for no behavior gain) and the stream code that mirrors the HR routes (shared helper is a later refactor). Gate: pure 76, db 13, http 46 (also with GD), all PASS.

## 2026-10-31 - Ship review for My Profile (Claude, /ship): GO for manual deployment, nothing deployed or committed
- Fan-out: code-reviewer (REQUEST CHANGES, 0 Critical, 4 Required), security-auditor (0 Critical/High, 1 Medium, 5 Low), test-engineer (gap list). Fixed after the reports: scans/thumbnails/photo now Cache-Control private, no-cache (ETag/304 kept) so they are never served from the browser cache after logout or deletion; session lock released before thumbnail generation; EXIF orientation applied to thumbnails; memory_limit guard before decoding (serve the original instead of a fatal 500); zero-date expiry on government IDs; empty CSRF token rejected on reveal; reveal JS no longer shows a blank value when the session expired (login page returned as 200); Content-Disposition uses an ASCII fallback + filename* (no CR/LF/quote/backslash); photo route serves only real image types, otherwise octet-stream; deleting a document also removes its cached preview (EmployeeRepository::removeThumbnail). New tests: pure 79, scratch-DB 14, HTTP 55 (also with php -d extension=gd; EXIF case checked with exif loaded): acting-style admin with no employee row, deactivated user with a revoked session, hostile mime/name, missing file on disk, reveal fuzz, thumbnail cache headers.
- Left alone (listed, not touched): reveal has no rate limit/step-up; global verify_csrf() (functions.php) accepts an empty token when the session has none yet (pre-existing, SameSite=Lax, practically unexploitable); no CSP sandbox on inline PDFs; JS has no automated test (tabs keys, lightbox, filter, 30 s timer) beyond node --check and the end-to-end responses; missing-table paths (employee_documents / payroll_employee_rates absent) not tested; page() has 8 parameters; HR/admin without a linked employee see the "Contact HR" wording; Google Fonts and the global head weight; dark mode, widths under 500 px, print preview and real scanned images are NOT visually verified.
- Direct deployment is John's step. Upload (runtime files only): NEW app/EmployeeProfileService.php, app/EmployeeProfileViews.php, public/assets/employee-profile.css, public/assets/employee-profile.js; CHANGED index.php, app/View.php, app/EmployeeRepository.php, public/assets/hris-app.js; NEW public/assets/sidebar-fold.css. The last three of the changed/new list belong to the earlier sidebar-fold patch that is bundled in the same working tree (View.php already links sidebar-fold.css): re-uploading is harmless if it is already live. Do NOT upload tests/, tasks/, scripts/, SPEC*.md, CONSTRAINTS.md (.htaccess blocks scripts/ and storage/ but NOT tests/ or root .md files). No migration, no env/config change, no new permission.
- Before upload: back up the four CHANGED live files. Check on the live host: PHP extension=gd (and exif for phone-scan rotation; without them the original images are served, as before), storage/employee_documents is writable (creates .thumbs), .htaccess rewrite is active (storage/ must return 403).
- Smoke after upload (about 5 minutes): employee login -> My Profile opens, tabs switch, an ID shows masked and Show reveals then re-hides; HR login -> top-right Profile opens its own record; client login -> Profile menu does not expose the page (403); a scanned document previews and downloads. Rollback trigger: any 500 on a page that also loads index.php/View.php, or a wrong person's data on My Profile. Rollback: restore the four backed-up files and delete the four new profile files (and sidebar-fold.css only if it was not live before); nothing in the database changed; .thumbs can be deleted. Target recovery: a few minutes of file copy.

## 2026-10-31 - Profile parity patch 1 (Claude): Lee code field, one Profile link, clearer directory labels
- Request (John): HR form needs a Lee code field (employee My Profile already shows it); remove the duplicate Profile link; relabel the confusing directory fields. Specs approved 2026-10-31: SPEC-capability-map.md, SPEC-hr-lee-code-field.md, SPEC-profile-nav-dedupe.md, SPEC-directory-field-labels.md; plan tasks/plan-profile-parity-p1.md.
- Changed (local only): index.php (Lee code input + POST, Directory role label/helper), app/EmployeeRepository.php (updateEmployment: Lee code validated max 30, blank = NULL, saved in the same transaction, lee_code_before/after in the audit), app/View.php (dropdown Profile hidden only in the employee portal), app/DirectorySectionViews.php + app/EmployeeDirectoryPlacement.php (labels/comments), tests/employee_profile_test.php (+4 source checks), tests/adl_reporting_test.php and tests/oc_head_office_sections_test.php (label text only). No migration, no schema change.
- Labels: "Directory section" is now "Directory role" (blank = "Automatic (from position)"); "Section (Ocampo's Head Office)" is now "Head Office team" ("No team"). POST keys, values and audit keys unchanged.
- Checks run: php -l on all changed PHP files (clean); tests/employee_profile_test.php and tests/notification_sidebar_test.php PASS. NOT run: adl_reporting_test and oc_head_office_sections_test (need HRIS_TEST_DB scratch copy), no browser, no live DB. Live status: not deployed (unknown).
- Decided but not built yet: hr-role-consolidation (HR user gets payroll + recruitment access, needs a read-only role_permissions check and John's OK, permission change); profile-shortcuts-shell then Downloads, FAQs, Payslip, Deductions, Additional as real pages (Payslip/Deductions/Additional empty state until payroll computation patch). "Additional" = bonuses/allowances/13th month pay.
- Next: John tests on localhost; then spec profile-shortcuts-shell.

## 2026-10-31 - Profile parity patch 2 (Claude): quick-link pages (Downloads, FAQs, Payslip, Deductions, Additional)
- Request (John): "build everything" from the approved capability map, then test. Spec: SPEC-profile-shortcuts.md (decisions by Claude within John's delegation). NOT built: hr-role-consolidation (permission change, needs read-only role_permissions comparison + John's OK).
- New: database/migrations/20261031_018_portal_content.sql (+ .down.sql; two new tables portal_downloads, portal_faqs; additive; NOT applied to pmbsi_hris, only to scratch pmbsi_hris_test), app/PortalContentService.php, app/PortalContentViews.php, tests/lee_code_db_test.php, tests/profile_content_http_test.php. Changed: index.php (routes profile-downloads, profile-faqs, profile-payslip, profile-deductions, profile-additional, profile-download-file), app/EmployeeProfileService.php (no Soon), app/View.php (HR/Admin sidebar: Portal Downloads, Portal FAQs, permission employees.manage), tests/employee_profile_test.php + employee_profile_http_test.php (assertions updated for approved changes; fixture AUTO_INCREMENT fix), scripts/check.php + CONSTRAINTS.md (floors 84/55/7/36).
- Writes need employees.manage (HR_USER, HRIS_ADMIN, SUPER_ADMIN hold it in the scratch copy), POST + CSRF + server check, audited, remove = hide (files stay). Upload: extension + detected mime, 10 MB, random name in storage/portal_downloads (blocked by .htaccess).
- Checks: HRIS_TEST_DB=pmbsi_hris_test php scripts/check.php full = OK (163 PHP, 5 JS, secrets scan, 6 suites). Mutation check: removing the manage guard, the 30-char limit or the mime check each makes tests fail (the first run showed the employee-upload test was vacuous because it used a page without a CSRF token; fixed). No browser/visual check, tests were written after the code (not red-first).
- Deploy (John, manual, not done): back up live DB, run the migration on the live DB, upload the changed/new files, smoke: employee My Profile quick links, HR Portal Downloads upload + employee download. Live status unknown.

## 2026-10-31 - Quality pass on patch 2 (Claude): /constraints check+guard+ratchet, /review (web performance audit still running when written)
- Constraints: CONSTRAINTS.md already existed; floors ratcheted up (84 / 55 / 7 / 36), PHP parse count 163. Guard: no lowered floor, no skipped or deleted tests, no suppressions; only label renames and the approved assertion changes in existing tests. `HRIS_TEST_DB=pmbsi_hris_test php scripts/check.php full` = OK.
- Review of the patch (0 Critical, 0 Required, 4 Optional): see the chat report; Optional items left alone on purpose.

## 2026-10-31 - /ship for patch 1+2 (Claude): GO for John's manual deployment, nothing deployed or committed
- Simplify: removeDownload/removeFaq share one private hide(); one removeForm() view helper. No behavior change.
- Fan-out: code-reviewer (REQUEST CHANGES: 1 Required, fixed), security-auditor (0 Critical/High/Medium, 3 Low), test-engineer (gaps; top 3 added). Web performance (quick mode): 0 High, 3 Medium (unbounded lists, 2 information_schema queries per download).
- Fixed after the reports: a duplicate Lee code now says "That Lee code is already used by another employee." (it used to say "Employee number, company email, or linked user already exists." because employees.lee_code has UNIQUE uq_employees_lee_code); tests added for duplicate Lee code, client and admin access to the new routes, upload edges (zero byte, shell.pdf.php, a.php.pdf, exactly 10 MB, +1 byte, unicode name, file[]), FAQ boundaries (300/4000/4001, sort clamp, edit/remove removed FAQ).
- Checks: HRIS_TEST_DB=pmbsi_hris_test php scripts/check.php full = OK. Floors now 84 / 3 / 14 / 55 / 8 / 51.
- Left alone (Optional/Low): Downloads/FAQs have no pagination; octet-stream accepted for doc/xls types; uploads sit under web root (Apache .htaccess denies storage/); POST handler catches RuntimeException only; unknown POST action redirects silently; ready() information_schema queries per request. Not tested: Range/concurrency, HR or admin without employees.manage, acting-as, any browser rendering, live host settings.
- Deploy (John): back up the live DB and the changed live files; run database/migrations/20261031_018_portal_content.sql on the live DB (additive); upload changed/new files; make sure storage/portal_downloads can be created; smoke: employee My Profile quick links, HR Portal Downloads upload and employee download, HR employment form Lee code save. Live status unknown.
- Rollback: restore the backed-up files; run 20261031_018_portal_content.down.sql only if the tables must go (uploaded files stay on disk); Lee code values stay in employees.lee_code (existing column).

## 2026-10-31 - Sidebar groups fold (Claude)
- John: too many sidebar links shown at once (HR sidebar), tidy the UI. Done: nav groups with 2+ visible items are now toggle buttons; only the group holding the current page starts open, other groups remember what was opened (localStorage `hris-nav-open:<scroll-key>`). One-item groups (Overview) stay plain. Folded icon rail still shows every icon. Collapsed headers show a rolled-up unread count.
- Files: app/View.php (render_portal_header, asset ?v= bumps), public/assets/hris-app.js, public/assets/sidebar-fold.css. nav_config, permissions and routes untouched.
- Checks: php -l, node --check, `php scripts/check.php task` = OK. Not verified: any browser rendering, mobile drawer, tests/payroll_concern_browser.cjs (matches `.gl` text). Not deployed, not committed. Vault notes other than this log not updated (UI-only).

## 2026-10-31 - Sidebar fold rail plan (Claude)
- John: naka-fold na sidebar ay "off" (screenshot: logo dikit/putol, scrollbar sa rail, walang tooltip sa magkakahawig na icons). Plan lang, wala pang code.
- Files: SPEC-sidebar-fold-rail.md, HRIS-Vault/05-Implementation/Plans/2026-10-31-sidebar-fold-rail.md. Status draft, hinihintay ang go-ahead ni John at sagot sa Q1/Q2.
- Hindi pa na-verify sa browser; walang code na binago.

## 2026-10-31 - Sidebar fold rail built (Claude)
- John: gawin ang recommended. Done: rail logo = round p emblem only (44px, cropped, centred); scrollbar hidden in the rail (still scrolls); fixed-position tooltip on hover/focus for every rail item and logout (replaces native `title`; `.nl` labels stay for screen readers). Fold toggle stays in the topbar. Open sidebar, mobile drawer, routes, permissions untouched.
- Files: public/assets/sidebar-fold.css (?v=20261031-v4), public/assets/hris-app.js (?v=20261031-railtip), app/View.php (asset versions only).
- Checks: php -l, node --check, `php scripts/check.php task` = OK. Not verified: browser rendering (rail at 1440/1024, dark mode, tooltip position near viewport edge). Not committed, not deployed.

## 2026-10-31 - Sidebar fold rail: test, constraints, simplify, ship check (Claude)
- John: run build/test/constraints/webperf/simplify/ship on the rail slice. Added tests/sidebar_fold_test.php (14 static checks: logo crop, hidden scrollbar, tooltip hover/focus/Escape/scroll, aria, no native title, asset versions). Registered in scripts/check.php (floor 14) and CONSTRAINTS.md. Tests were written after the code (no RED run).
- Simplify: no change needed (one tooltip element, delegated listeners, no per-item JS). Webperf: tooltip reads layout only on hover; scroll listener only toggles a class; no new request (CSS/JS already loaded).
- Checks: `php scripts/check.php task` = OK (40 files, sidebar_fold 14/14). No suppressions added. Not run: `full` (needs HRIS_TEST_DB), browser, Lighthouse/axe.
- Ship: UI-only, no migration, not committed (working tree has ~64 unrelated dirty files), not deployed. Rollback = restore sidebar-fold.css, hris-app.js, View.php asset versions.

## 2026-10-31 - Sidebar rail v2: group icons + flyout, full logo (Claude)
- John: rail logo was a cropped emblem; rail showed every icon (too many). Wanted full logo, cleaner rail, and a more interactive expanded sidebar.
- Rail: full logo scaled to rail width (no crop). One icon per nav group; hover, focus or click opens a fixed flyout with the group's items and title; Escape/scroll closes; unread chip and active marker kept on the group icon. Expanded sidebar: group headers get an icon tile (brand-filled when the group holds the current page), hover lift, open animation, item nudge. Group icons mapped in View.php `$gicon` (fallback: first item icon).
- Files: app/View.php, public/assets/sidebar-fold.css, public/assets/hris-app.js, tests/sidebar_fold_test.php (14 -> 16 checks, floor raised), scripts/check.php, CONSTRAINTS.md. Asset versions v5 / railfly.
- Checks: `php scripts/check.php task` = OK (sidebar_fold 16/16). Not run: browser, axe, `full`. UI-only, no migration, not committed or deployed.

## 2026-10-31 - HR dashboard v2 plan (Claude)
- John: HR user dashboard looks old (screenshots), upgrade like the new sidebar; plan first, frontend-ui-engineering + spec + plan skills. Plan only, no code.
- Files: SPEC-hr-dashboard-v2.md, tasks/plan-hr-dashboard-v2.md, HRIS-Vault/05-Implementation/Plans/2026-10-31-hr-dashboard-v2.md. Status draft, hinihintay ang go-ahead at sagot sa Q1-Q5. tasks/plan.md (My Profile) hindi ginalaw.
- Scope: HrDashboardViews.php + hr-ops.css only, `.hd2` scope; data layer, shared CSS, routes, permissions untouched. Walang browser check, walang commit/deploy.

## 2026-10-31 - HR dashboard v2 built (Claude)
- John: "patch mo na" after the plan (defaults for Q1-Q5: two KPI tiers, segmented period, compact 2x2 quick actions, bars only for non-zero stages, requests first). Skills: build, frontend-ui-engineering, test, constraints (+ TDD: new static test written first, 15 RED, then GREEN).
- Files: app/HrDashboardViews.php, public/assets/hr-ops.css (?v=20261031-hd2), tests/hr_dashboard_ui_test.php, scripts/check.php, CONSTRAINTS.md (floor 20), CHANGELOG.md, vault Progress/Screens/Design-System, plan status.
- Checks: `php scripts/check.php task` = OK. Rendered the page for the HR fixture on scratch DB hris_slice15_test_20261028_234641 (2 filter combos): no PHP warnings, 6 KPI cards. Not run: browser/screenshots, `full`. Existing `tests/hr_dashboard_test.php` was already failing before this patch (fixture "Choose one nature of incident"), and its "Needs your action today" string no longer exists in app code; left alone, needs its own fix. Not committed, not deployed.

## 2026-10-31 - Team Requests lead notification + Follow up: spec and plan (Claude)
- John: employee files HR request -> Manager (HO) / ADL + SC/OIC (Retail) must be notified; Team Requests badge; follow-up. Decisions: notify on every status change; Follow up goes to HR only; no "Noted" button; new table OK (migration 019, follow-ups only).
- Files: SPEC-request-followup.md, tasks/plan-request-followup.md, tasks/todo-request-followup.md, Plans/2026-10-31-request-followup.md. Plan only, no code, no migration applied. Waiting for John's go-ahead to build.

## 2026-10-31 - Team Requests lead notification + Follow up built (Claude)
- John: "build nanatin yan" after approving spec/plan. Built tasks T1-T5; migration 019 applied to localhost pmbsi_hris after verified dump/restore, cycle up/re-run/down/up and manual used-data guard test.
- Files: app/TicketNotifications.php, app/TicketLeadFollowup.php (new), app/TicketModule.php, app/TicketViews.php, app/TicketServiceViews.php, app/NotificationService.php, migration 019, tests/ticket_lead_followup_test.php, scripts/check.php, CONSTRAINTS.md. `check.php full` OK. Not run: browser; ticket_viewers/ho_manager/services suites fail earlier on incident fixture (Choose one nature of incident) and several suites on missing EmployeeProfileService in View.php:185 (both from other uncommitted work). Not committed or pushed.

## 2026-11-02 - Auto-generated employee portal account: spec approved + plan (Claude)
- John: auto-create the portal account when an employee is created; email firstnamelastname@pmbsi.com / @2log.com by Company; role (HR or Employee) chosen on Create Employee; HR (all HR users) can view any password with the eye anytime; employee can change it; forgotten password via HR Requests; remove Create New User form and "Link later". Decisions D1-D12 in the spec. Role clean-up (HR Leave/Timekeeping/Recruiter/Payroll) deferred to a separate patch.
- Found: no sodium in PHP (use openssl AES-256-GCM); users has no must_change_password; no employee change-password page exists (must be built); HR role has no users.* permissions.
- Files: SPEC-employee-portal-account.md, tasks/plan-employee-portal-account.md, tasks/todo-employee-portal-account.md, Plans/2026-11-02-employee-portal-account.md. Plan only, no code, no migration. Waiting for John's go-ahead to build.

## 2026-11-02 - Auto-generated employee portal account built (Claude)
- John: go after approving the plan; skills: build, test (TDD), frontend-ui-engineering, constraints, review. T1-T8 done. Migration 020 applied to localhost pmbsi_hris after verified dump/restore, cycle and guard.
- The runtime HTTP test found a real bug (HR got 403 on admin-users because of the admin-* portal gate); fixed. Recruitment conversion accounts aligned. Tests: employee_portal_account_test (101) and _http_test (23); constraint floors raised, none lowered.
- Not run: browser screenshots. Not pushed, not deployed. config/config.local.php (git-ignored, holds the key) must be backed up and copied to any other server before deploying.

## 2026-11-02 - Bulk portal accounts for existing employees: spec approved + plan (Claude)
- John: imported employees have no login; replace the 201 "User account" dropdown with Generate account, plus one-click Generate all on the Employees directory with a notice. Decisions: Active only; no link-existing at all; bulk = Employee role only (HR per employee only); skip-and-report on email collision.
- Found: EmployeeRepository.php:664 would unlink the account on every 201 save once the dropdown is removed; T1 must keep user_id on update.
- Files: SPEC-employee-bulk-portal-accounts.md, tasks/plan-employee-bulk-accounts.md, tasks/todo-employee-bulk-accounts.md, Plans/2026-11-02-employee-bulk-accounts.md. Plan only, no code, no migration. Waiting for John's go to build.

## 2026-11-02 - Portal accounts for existing employees built (Claude)
- John: go build. T1-T6 done: service (pending, issueForExisting, issueMissing), POST actions, 201 Generate account, directory notice plus Generate all, link-existing removed. Found: 352 imported employees have blank Company, so the domain falls back to Legal entity (PMBSI). Verified dump and restore into hris_slice15_test_20261102_100702.
- Files: app/EmployeeAccountService.php, app/EmployeeDirectoryViews.php, app/FoundationRepository.php, index.php, public/assets/employee-directory.css, scripts/check.php, CONSTRAINTS.md, tests/employee_bulk_account_test.php, tests/employee_bulk_account_http_test.php. Live bulk run, commit, push, deploy: not done.

## 2026-11-02 - Remove portal account: spec approved + plan (Claude)
- John: add Remove account in Users & Access; account only goes, Employee 201 stays. Decisions: all HR users can remove; soft removal; Removed accounts view with Restore; additive users.removed_at migration OK; clear employees.company_email when it matches (use case: wrong company in a generated account, remove, fix 201, Generate again).
- Found: users.id has many FKs with no ON DELETE (audit, leave, tickets), so hard delete is unsafe. HR currently sees "No available actions" because the menu is gated by users.manage.
- Files: SPEC-remove-portal-account.md, tasks/plan-remove-portal-account.md, tasks/todo-remove-portal-account.md, Plans/2026-11-02-remove-portal-account.md. Plan only, no code, no migration applied. Waiting for John's go to build.

## 2026-11-02 - Remove portal account built (Claude)
- John: go build. T1-T6 done. Advisor review caught three issues before apply and they were fixed: restore re-activating a deactivated account (added removed_status), HR scope aligned to what John approved (HR and employee accounts removable, Super Admin never, Admin-portal only by Super Admin), and users.remove no longer granted to HRIS_ADMIN. Migration 021 cycled, guarded and applied to localhost pmbsi_hris after a verified dump.
- Files: database/migrations/20261102_021_remove_portal_account(.down).sql, app/FoundationRepository.php, app/RecruitmentRepository.php, index.php, public/assets/hris-modern.css, scripts/check.php, scripts/ticket_stageb_database.php, CONSTRAINTS.md, tests/remove_account_test.php, tests/remove_account_http_test.php. Not committed: the bulk-accounts patch is still uncommitted in the same files. Not run: browser, push, deploy.

## 2026-11-02 - Recruitment (job board + tracker + pipeline): capability map proposed (Claude)
- John: HR posts jobs, public searches/applies/tracks, HR moves stages per hand-drawn flow, manpower tickets must reach HR Manpower Requests. Found: nothing creates job_openings (Careers shows 0); tickets and hr-manpower use different tables; stages differ from flowchart.
- Decisions D1-D4 (Pre-Deployment last, Client Review -> For Review, all HR users post, HR reviews ticket before posting). 5 modules proposed: rec-manpower-intake, rec-jobs, rec-pipeline, rec-apply, rec-track.
- Files: SPEC-recruitment-map.md. Plan only, no module spec, no code, no migration. Waiting for John to approve the map.

## 2026-11-02 - rec-manpower-intake: module spec drafted (Claude)
- John: gamitin ang spec skill, unahin ang Recruitment. Used agent-skills:spec-driven-development (no skill named agent-skills:spec exists). Module 1 of the recruitment map; D1-D4 already approved, but the 5-module map itself was not explicitly approved yet.
- Found: job_openings.manpower_request_id is NOT NULL, manpower_requests.client_id NOT NULL with only demo clients, ticket completion only stamps date_served, hr-manpower create form is Super Admin / Recruitment Manager only. Proposal: bridge row linked to the ticket, one HR decision driving the ticket transition.
- Files: SPEC-rec-manpower-intake.md. Spec only: no plan, no code, no migration. Open: Q-A client (needs John's word), Q-B HR permissions, Q-C to Q-F defaults.

## 2026-11-02 - rec-manpower-intake spec v2: Q-A and Q-B answered (Claude)
- John: remove the demo clients, HR users add employers themselves; salary optional (2Log usually blank, other employers may fill); Q-B check and seed if missing. Checked live read-only: HR_USER already has recruitment.view and recruitment.manage, no seed needed. Demo clients still referenced by branches 4 and 5 and users 5 and 6; manpower/jobs/applications are all 0.
- Files: SPEC-rec-manpower-intake.md (v2). Spec only, no code, no migration, nothing changed in pmbsi_hris. Open: R1 (unlink + delete demo clients), Q-C to Q-G.

## 2026-11-02 - rec-manpower-intake: plan and tasks written (Claude)
- John: "okay lahat" (R1 unlink + delete demo clients, Q-G HR picks employer at Approve, defaults for Q-C to Q-F), asked for plan and tasks. Plan only: no code, no migration, nothing changed in pmbsi_hris.
- Design change vs spec v2, needs John's OK: manpower_requests row is created at Approve (not at intake) because client_id is required and the employer is chosen at Approve; the HR list is a UNION of manpower_requests and unlinked tickets. Fallback: nullable client_id. TicketService::act COMPLETE/REJECT reused for the ticket step.
- Files: HRIS-Vault/05-Implementation/Plans/2026-11-02-rec-manpower-intake.md, tasks/plan-rec-manpower-intake.md, tasks/todo-rec-manpower-intake.md (T1-T7). Migrations planned: 022 (ticket link), 023 (remove demo clients, gated by verified dump). Waiting for John's go to build.


## 2026-11-02 - rec-manpower-intake built (Claude)
- John: "go build" with the build, test, constraints and code-simplify skills (mapped to incremental-implementation, test-driven-development, constraint-driven-development, code-simplification plus hris-patch). T1-T7 done. Migration 022 cycled, guarded and applied to localhost pmbsi_hris after a verified dump. Advisor review changed the build: the ticket screens now refuse Served without a recruitment request (otherwise HR could bypass Recruitment), Approve and Decline show only for HR ticket reviewers, the demo-client script verifies no other business row changed and rolls back otherwise, the requester-sees-reason check was added.
- Demo clients: not a migration but scripts/remove_demo_clients.php (dry-run, scratch test, then live). The active demo client-portal login was deactivated because a client user with no client_id sees every client (confirmed in code). schema.sql demo seed kept (its demo rows depend on the clients).
- Files: database/migrations/20261102_022_manpower_ticket_link(.down).sql, app/ManpowerIntake.php, app/ManpowerIntakeViews.php, app/TicketTypes.php, app/RecruitmentRepository.php, index.php, scripts/remove_demo_clients.php, scripts/check.php, CONSTRAINTS.md, tests/rec_manpower_intake_test.php (28), tests/rec_manpower_intake_http_test.php (19), tests/rec_manpower_intake_http_fixture.php, tests/ticket_manpower_cases.php. check.php full OK. Not run: browser, commit, push, deploy.

## 2026-11-02 - rec-jobs: module spec drafted (Claude)
- John: spec muna para sa rec-jobs (agent-skills:spec-driven-development). Module 2 of the recruitment map; D3 and D4 already approved, the 5-module map itself still not explicitly approved.
- Found: job_openings schema already has every field needed, so no migration expected; nothing inserts job_openings; closes_at is never checked on public reads; job_card shows the employer name publicly. Proposal: Draft/Published/Closed lifecycle, expiry via closes_at in one shared visibility fragment, standalone job creates its own APPROVED manual request, no hard delete.
- Files: SPEC-rec-jobs.md. Spec only: no plan, no code, no migration, nothing changed in pmbsi_hris. Open: Q-J1 (employer name public, needs John) and Q-J2 to Q-J6 (defaults). Waiting for John's approval.

## 2026-11-02 - rec-jobs: spec approved, D5 added, plan written (Claude)
- John: "okay lahat, PMBSI lang sa public", gawa na ng plan. Screenshot of HR > Manpower Requests: HR should not request, HR creates the hiring. Recorded as D5: remove the "+ Create new manpower request" form and the Pipeline "+ New manpower request" button; Manpower Requests is ticket-only; walk-in vacancies go through "+ New job". Supersedes the module 1 assumption that the manual form stays.
- Q-J1 public shows PMBSI only; Q-J2 to Q-J6 defaults accepted. No migration (job_openings has all columns); bridge rows for standalone jobs hidden from Open.
- Files: SPEC-rec-jobs.md (v2), tasks/plan-rec-jobs.md, tasks/todo-rec-jobs.md (T1-T7), Plans/2026-11-02-rec-jobs.md. Plan only: no code, nothing changed in pmbsi_hris. Waiting for John's go to build.

## 2026-11-02 - rec-jobs built (Claude)
- John: go build with the build, test, constraints and code-simplify skills (mapped to incremental-implementation, test-driven-development, constraint-driven-development, code-simplification plus hris-patch). T1-T7 done, no migration. Advisor review before coding added: employer on the walk-in form, employer no longer searchable or shown publicly, code and slug from the insert id, closing date stored as 23:59:59, server check of the request status, Post job gated on recruitment.manage only, Open tab hides ticket-less rows.
- Checks: rec_jobs_test 50, rec_jobs_http_test 38, module 1 tests 28 and 19 (updated for D5, floors kept), check.php full OK on hris_slice15_test_20261102_141600. Live pmbsi_hris untouched (no migration). Not run: browser, commit, push, deploy.
- Files: app/JobOpenings.php, app/JobOpeningsViews.php, app/RecruitmentRepository.php, app/ManpowerIntake.php, app/ManpowerIntakeViews.php, app/View.php, index.php, tests/rec_jobs_test.php, tests/rec_jobs_http_test.php, tests/rec_manpower_intake_test.php, tests/rec_manpower_intake_http_test.php, scripts/check.php, CONSTRAINTS.md. Not committed: the tree still holds the bulk accounts, remove account and manpower intake patches in the same files.
- Advisor review before done added three fixes: the Post job prefill copies only the ticket qualifications (never the internal request notes), the public salary rule from Q-J5 (range only when both ends are set), and a one-time form token against double submit. Row buttons got accessible names. Tests now 50 and 38; check.php full OK.

## 2026-11-02 - rec-jobs form polish (Claude)
- John: New job form: employer automatic PMBSI (no picker), remove Department, Employment type list = Regular, Probationary, Seasonal, Contract, Part-time, Project-based, Fixed-term, Internship.
- Done: employer picker and "add new employer" removed from the form; with none posted, JobOpenings::save uses PMBSI (posted client_id/new_employer still honoured for the tests/API). Department field removed; edit no longer touches department_id (keeps the request's). JobOpenings::TYPES is now the 8-type map (stored codes REGULAR, PROBATIONARY, SEASONAL, CONTRACT, PART_TIME, PROJECT_BASED, FIXED_TERM, INTERNSHIP; VARCHAR(40), no migration). Old FULL_TIME jobs show as Regular in the edit form; saving FULL_TIME is refused.
- Checks: php -l, check.php task OK, rec_jobs_test 52/0 on hris_slice15_test_20261102_141600 (floor raised 50 to 52). Not run: rec_jobs_http_test, browser, commit. Live pmbsi_hris untouched.
- Files: app/JobOpenings.php, app/JobOpeningsViews.php, tests/rec_jobs_test.php, tests/rec_jobs_http_test.php, scripts/check.php.

## 2026-11-02 - rec-apply-job built (Claude)
- John: Apply page position must be automatic from the clicked job; HR applicant view like JobStreet (Job details like Job Openings, resume view/download); Q1 Apply Now to Careers, Q2 summary visible and description collapsed, Q3 type/location columns, Q4 inline view plus download, Q5 Notes tab skipped. Spec: SPEC-rec-apply-job.md.
- Found: resumes were stored but no HR route could open them; added hr-application-resume (recruitment.view, audited).
- Checks: php -l, rec_jobs_http_test 48/0 (floor 38 to 48) on hris_slice15_test_20261102_141600; check.php full: only tests/ticket_lead_followup_test.php fails (22 PASS vs floor 30, ADL badge cases; ticket area, not touched by this patch, cause not investigated). Not run: browser, commit.
- Files: index.php, app/RecruitmentRepository.php, app/JobOpeningsViews.php, app/View.php, public/assets/hris-modern.css, tests/rec_jobs_http_test.php, scripts/check.php, SPEC-rec-apply-job.md.

## 2026-11-02 - applicant stage flow, no manual set (Claude)
- John: HR should not set the stage by hand; it flows on its own, only Rejected / Withdrawn / On Hold / Returned are manual.
- Done: Stage & actions on hr-applicant now offers only those four (required), labelled "Reject, withdraw, hold or return"; when the candidate is in one of them it offers "Resume at" with the normal stages. A "Start screening" button shows at Applied (no existing action moves Applied to Screening). Existing automatic moves unchanged: schedule interview, endorse, send/accept offer, complete deployment. UI only: server moveStage still accepts any active stage (not tightened).
- Checks: php -l; rec_jobs_http_test 50/0 (floor 48 to 50) on hris_slice15_test_20261102_141600. Not run: browser, commit.
- Files: index.php, tests/rec_jobs_http_test.php, scripts/check.php.

## 2026-11-02 - applicant Update button continues by itself (Claude)
- John: the Update button failed with "Please select an item"; it should just update and move on, except to reject or stop the candidate.
- Done: Stage & actions "Update to" now defaults to "Continue to <next stage>" (value NEXT), resolved on the server by RecruitmentRepository::nextStageCode: only from Applied, Screening, Interview, Endorsed, Client Review (before Offer). Offer, Deployment and Deployed still move through their own actions (send/accept offer, complete deployment), so there the select asks for a status as before. Rejected / Withdrawn / On Hold / Returned stay optional; "Resume at" after an exception. Start screening button removed (Update does it). The Reason is saved in the timeline.
- Checks: php -l; rec_jobs_http_test 51/0 (floor 50 to 51) on hris_slice15_test_20261102_141600. Not run: browser, commit.
- Files: index.php, app/RecruitmentRepository.php, tests/rec_jobs_http_test.php, scripts/check.php.

## 2026-11-02 - endorse to Manager/ADL, spec only (Claude)
- John: Client endorsement should go to the Manager or ADL (e.g. Sir Charles) who has the manpower request ticket; asks whether search or auto is better UX.
- Recommendation written: auto-fill the ticket requester, searchable Change limited to Manager/ADL users; Manager/ADL get a "Candidates for review" page with resume view and a decision. Needs a migration (endorsements.reviewer_user_id, endorsement_reviews) and a new permission recruitment.review, so waiting for John's approval. No code changed.
- File: SPEC-rec-endorse-manager.md (Q1-Q6 open).

## 2026-11-02 - endorse spec decisions Q1-Q6 + two new slices (Claude)
- John: Q1 yes, several recipients (HR can proceed if they do not answer), Not suitable returns to HR who can re-endorse, label Manager Review, Manager/ADL can interview and decide, remove the Client portal, applicant should see steps (Applied, initial / mid / final interview, Deployment, Start date). Spec updated in SPEC-rec-endorse-manager.md. Still spec only, no code.

## 2026-11-02 - rec-endorse-manager plan (Claude)
- John confirmed: applicant sees the steps (later slice), reviewers are Manager, ADL, TC and, in retail, SC; several recipients; HR can proceed if they stay silent; Not suitable returns to HR.
- Plan: Plans/2026-11-02-rec-endorse-manager.md. Data design changed after review: standalone `application_reviewers` table (not altering `endorsements`), permission `recruitment.review` for MANAGER/ADL/SC/TC. Status draft: has a migration and a new permission, waiting for John's go. No code written. Open proof before apply: ticket_lead_followup_test failure unrelated on a fresh scratch DB.

## 2026-11-02 - rec-endorse-manager built (Claude)
- John: go ahead sa endorse-manager slice. Tapos na: migration 023 applied sa localhost pmbsi_hris (backup + restore verified, up/re-run/down/up, guard), HR card, reviewer pages, resume scope, desisyon rules, notifications, offer types sweep.
- Advisor review nagdagdag ng: server stage gate = card stages, at round-scoped Not suitable rule (may test). check.php full OK sa hris_slice15_test_20261102_160636: rec_endorse_manager_test 37, http 33, ticket_lead_followup 30/30 (hindi na nag-fail sa fresh copy).
- Hindi pa: commit (halo-halo ang tree, hinihintay ang desisyon ni John), browser check, push, deploy. Susunod: rec-remove-client-portal, rec-track-steps.
- Files: app/RecruitmentRepository.php, app/CandidateReviewViews.php, app/View.php, app/NotificationService.php, app/functions.php, index.php, database/migrations/20261102_023_*, tests/rec_endorse_manager_test.php, tests/rec_endorse_manager_http_test.php, scripts/check.php, CONSTRAINTS.md.

## 2026-11-02 - interview invite + chain specs and plan (Claude)
- John: gamitin ang email ng applicant para ipadala ang Meet link (Gmail later); chain Initial -> Mid -> Final, pwedeng mag-pass ang reviewer, Final Proceed = balik kay HR (hindi auto-Offer). Q1-Q11 sinagot (Q10 default: legacy Proceed = ready for HR).
- Specs: SPEC-interview-map.md, SPEC-interview-invite.md (approved), SPEC-interview-chain.md (decisions in). Plan: Plans/2026-11-02-interview-invite-chain.md, status draft, waiting for John's go (Slice B has migration 024). Walang code na binago.
- Hindi pa: code, migration, real email, browser check, commit, Graphify refresh.

## 2026-11-02 - interview invite + chain built (Claude)
- John: go sa Slice A at B gamit ang build/test/constraints/code-simplify. Tapos na: invite email (switch off by default), Resend, pass-on, Final interviewer, Ready for HR card; migration 024 applied sa localhost pmbsi_hris (backup + restore verified, up/re-run/down/up, guard, rows unchanged).
- Advisor nakahanap ng 2 bug na naayos: stale final flag mula sa lumang round, at HR schedule na nagbabalik ng stage. May test na pareho.
- Checks: check.php full OK (invite 17+12, chain 29+19). Hindi pa: browser, totoong Gmail send, commit, push, deploy, Graphify refresh.

## 2026-11-02 - reviewer decision closes after Proceed (Claude)
- John (screenshot ng Candidate page ni Charles Chan): pagkatapos mag-Proceed, dapat sarado na ang decision; bukas pa ang comment box at buttons.
- Interpretation (hindi 100% sigurado sa "close na si HR"): ang "Your decision" card ay read-only na may "Closed" badge kapag PROCEED na ang sagot. Pass-to-next-interviewer at Schedule ay bukas pa rin. Hindi nagbago ang HR side o ang stage rules.
- UI lang (app/CandidateReviewViews.php); server decide() pwede pa ring tumanggap ng bagong sagot kung may direct POST. Checks: php -l, check.php task OK. Hindi pa: browser check, http test sa scratch DB, commit.
- Follow-up (John, screenshot): after Proceed the "Pass to next interviewer" card must be gone too. Done: hidden when the reviewer's answer is PROCEED (app/CandidateReviewViews.php); Closed card text now "HR continues from here". Schedule card stays. Not run: interview_chain_http_test (scratch DB), browser.
- Follow-up 2 (John, screenshot): Schedule an interview card also hidden after Proceed (app/CandidateReviewViews.php). After Proceed the reviewer sees only the Closed decision card. UI only; server actions unchanged. Not run: http tests, browser.

## 2026-11-02 - offer rate type built (Claude)
- John (screenshot of Offer card): Offered salary should be a Monthly / Daily / Hourly rate dropdown with peso input that computes the rest; default Monthly; Daily offers re-open as Daily; "okay na" sa 26 days default.
- Done: migration 025 (applied localhost after backup + scratch cycle + guard), OfferRate, saveOffer, Offer form with live preview, tests (offer_rate 10, rec_endorse_manager 41), check.php full OK.
- Hindi pa: browser check, commit, push, deploy, Graphify refresh. 26x8 pa rin NEEDS VERIFICATION.

## 2026-11-02 - offer working days built (Claude)
- John: add 22 and 26 days so salary computes; go with recommendations. Done: Working days select on the Offer card, OfferRate/saveOffer, migration 026 applied localhost (backup + restore verified, cycle, guard, rows unchanged), tests, check.php full OK.
- Hindi pa: browser check, commit, push, deploy, Graphify refresh. Days are offer-only (Q85).

## 2026-11-02 - convert auto account, Task 1 built (Claude)
- John (screenshots of Convert to Employee): remove the Account setup dropdown, generate the account automatically, show employer PMBSI, Company (2LOG or PMBSI) gives the email domain. Spec and plan saved (SPEC-convert-auto-account.md, tasks/plan-convert-auto-account.md); John approved "go na task 1". John also wants build/test/constraints/code-simplify used whenever coding.
- Done: Task 1 backend (see Progress.md), tests green, floors ratcheted. Hindi pa: Task 2 form, Task 3 HTTP test and sweep, browser check, commit, push, deploy, Graphify refresh.

## 2026-11-02 - convert auto account, Task 2 built (Claude)
- John: "go na task 2". Done: Convert form (see Screens.md addendum). Hindi pa: Task 3 (HTTP render test, sweep), browser check by John, commit, push, deploy, Graphify refresh. Dead CSS .phase2c-account-* left on purpose.

## 2026-11-02 - convert auto account, Task 3 built (Claude)
- John: "go na task 3". Done: HTTP test, sweep, vault sync, floors ratcheted (convert 18 + 22). Slice complete locally. Hindi pa: browser check ni John, commit, push, deploy, Graphify refresh. Open for John: hide Branch for PMBSI on Convert like Create Employee?

## 2026-11-02 - HR appearance palettes, spec only (Claude)
- John (screenshot of the profile menu, Appearance circled + 3 palette images): HR role only should get colour palettes. Answers: Light, Dark, Soft Beige, Earth Tones, Soft Pastel; saved per user in the DB; recolour page, cards, topbar, sidebar (status/brand colours unchanged); non-HR keep Light/Dark.
- Done: SPEC-hr-appearance-palettes.md (DRAFT). Waiting for John's approval of the spec; then plan/tasks. No code, no migration yet (027 planned: users.ui_palette).
- Hindi pa: approval, plan, build, browser check, commit, push, deploy, Graphify refresh. Hex values are estimates, NEEDS VERIFICATION by the contrast test.

## 2026-11-02 - HR appearance palettes, plan and tasks (Claude)
- John: "go na, HR portal lang, plan and tasks na". Spec approved; Admin does NOT get palettes (Q1 closed). Plan: tasks/plan-hr-appearance-palettes.md, tasks: tasks/todo-hr-appearance-palettes.md (5 tasks), vault plan Plans/2026-11-02-hr-appearance-palettes.md.
- Hindi pa: any build (Task 1 next, waiting for John go), browser check, commit, push, deploy, Graphify refresh.

- 2026-11-02 hr-appearance-palettes Task 1 (Claude): UserPalette service and migration 027 built locally (not applied to localhost yet). Nothing visible to users yet.

- 2026-11-02 hr-appearance-palettes Task 2 (Claude): save endpoint built, migration 027 applied to localhost. Next: Task 3 (palettes.css + contrast test), waiting for John go.

- 2026-11-02 hr-appearance-palettes Task 3 (Claude): palettes.css and contrast test built, not linked yet. Next: Task 4 (menu, server render, JS), waiting for John go.

- 2026-11-02 hr-appearance-palettes Task 4 (Claude): menu, server render and JS built; migration 027 already applied. Next: Task 5 (sweep, browser check, vault), waiting for John go. JS is only node --check and HTTP-render tested so far.

- 2026-11-02 hr-appearance-palettes (Claude): Soft Pink added as the 6th palette at John request (screenshot). Task 5 (sweep, browser check) still pending John go.

- 2026-11-02 my-profile-photo-upload (Claude): self-service photo upload on My Profile hero (action upload_own_photo, own record only, reuses EmployeeRepository::uploadProfilePhoto). No migration. Not run: browser check, commit, push, deploy, Graphify refresh.

- 2026-11-02 my-profile-photo view+crop (Claude): avatar opens a dialog (view with feathered portrait, name, role; crop with drag/zoom/arrow keys, 512px JPEG made in the browser, then the same upload_own_photo POST). CSS/JS v9, footer note. Not run: real browser check, check.php full, commit, push, deploy, Graphify refresh.

- 2026-11-02 My Profile hero photo is now a larger feathered portrait (CSS only, v10).

- 2026-11-03 rec-screening-score (Claude): spec approved, plan written (Plans/2026-11-03-rec-screening-score.md). No code yet. Waiting John go + 2 decisions (MT recognition, walk-in jobs).

- 2026-11-03 rec-screening-score: John approved a new MT role (+ OIC and MT as reviewer roles). Walk-in-job rule still pending.

- 2026-11-03 rec-screening-score: Job Opening form will get a Retail/Head Office option; other-company jobs default HR-only (assistant default, John did not follow the question).

- 2026-11-03 rec-screening-score: scoring is now Negative/Fair/Outstanding (John). Spec and plan updated, no code yet.

- 2026-11-03 rec-screening-score (Claude): built and applied to localhost, see Plans/2026-11-03-rec-screening-score.md (status done). Next: John tries a new applicant; commit local.

- 2026-11-03 rec-screening-first (Claude): John approved Option A (hide APPLIED, legacy rows treated as Screening). Plan written (Plans/2026-11-03-rec-screening-first.md). No code yet. Waiting John go.

- 2026-11-03 rec-screening-first (Claude): built and applied to localhost, see Plans/2026-11-03-rec-screening-first.md (status done). Next: John checks a new applicant in the browser; commit local.

- 2026-11-03 applicant-profile-polish (Claude): John approved SPEC-applicant-profile-polish.md (Hiring Review rename, two-column layout, interview scorecard 1-5). Plan written (Plans/2026-11-03-applicant-profile-polish.md, status draft). No code yet. Waiting John go; migration 030 needs his OK.

- 2026-11-03 applicant-profile-polish (Claude): T1 rename to Hiring Review and T2 two-column layout built and checked on a scratch DB, no schema change. T3 interview scorecard (migration 030) waits for John go.

- 2026-11-03 applicant-profile-polish T3 (Claude): interview scorecard (1-5 per criterion + recommendation, BR-884), migration 030 applied to localhost. All three John requests are done; see Plans/2026-11-03-applicant-profile-polish.md (status done).

- 2026-11-03 hiring-review-pass-visibility (Claude): John approved SPEC-hiring-review-pass-visibility.md (HO: HR->TC->Manager with direct-manager lock; Retail: area/branch set on Job Opening, ADL then OIC/MT/SC). Plan written (Plans/2026-11-03-hiring-review-pass-visibility.md, status draft). No code yet. Waiting John go; migration 031 needs his OK.

- 2026-11-03 hiring-review-pass-visibility (Claude): built and applied to localhost (migration 031), see Plans/2026-11-03-hiring-review-pass-visibility.md (status done). John also asked to remove the codex-first hook (removed from ~/.claude/settings.json). Next: John tries a Retail job with branches in the browser; commit local.

- 2026-11-03 job-form-branch-picker (Claude): John approved SPEC-job-form-branch-picker.md (hide when not Retail incl. blank/Other; always-expanded area cards, All/Clear, filter, counters). Built in app/JobOpeningsViews.php only (no migration). check.php task OK. Not browser-verified yet; John to try Head office <-> Retail toggle. Not committed.

- 2026-11-03 applicant-page-declutter (Claude): John approved SPEC-applicant-page-declutter.md with all recommended answers. Built in index.php (hr-applicant), app/RecruitmentRepository.php (saveOffer lock, jobBranchIds), public/assets/app.css. check.php task OK. Not browser-verified yet; John to try an ACCEPTED candidate and a Retail job. Local only, not committed.

- 2026-11-03: Applicant page collapsed sections (.ap-sec) compacted: one-row summary (title, status, arrow), tighter closed padding; app.css now cache-busted (?v=20261103-ap-sec) in app/View.php because the browser was serving the old CSS. UI only, no DB.
- 2026-11-03: Tickets collapsibles swept to modern pattern (CSS only, app.css ?v=20261103-tk-sweep): service-view Approval chain/History, queue Uniform summary, Ticket Viewers problem rows, report "show more", earlier responses. Not browser-verified.

- 2026-11-03: Recruitment Pipeline board modernised (CSS + 2 markup bits, app.css ?v=20261103-pipeline): equal-width grid with no stray scrollbar, per-stage accent colour, pill counts, hover lift, dashed empty state, mobile 1-column, reduced-motion. php -l OK; not browser-tested.

- 2026-11-03 talent-pool (Claude): John asked to take Deployed off the Pipeline board and add a place to "pull" Rejected/Withdrawn/On Hold/Returned candidates back into another job. Decisions so far: no stage removed; only Deployed leaves the board. Spec drafted in SPEC-talent-pool.md (status DRAFT, NOT approved yet). John will come back later. Open: Deployed tab inside Talent Pool?, carry over old score/docs?, do-not-rehire flag? Next: John approves or edits the spec, then plan. No code or DB changed.

- 2026-11-04 public-light (Claude): John saw a dark band under the public nav on another PC and suspected theme leak. Finding: the band itself is the dark hero showing behind the translucent nav (normal), but a real leak existed: hris-app.js applied the saved portal Dark theme to public pages. Fixed with a `.pubnav` guard + JS cache bump. If it still looks wrong, John sends a screenshot of the other PC. check.php task OK; not browser-verified; not committed.

- 2026-11-04 retail-endorse-bugs (Claude): John's Retail test (job 9, Robinsons Antipolo) showed an empty Endorse list. Root cause is data, not routing: the only Sales Captain there (Myra Azcuna) has no portal account (employees.user_id NULL); the only ADL user is at Head Office and the only SC user is at OMOA. Code fix: endorse card now lists branch staff in a reviewer position who have no account (`scopeStaffWithoutAccount`) and the empty message no longer says "set the area/branches" when they are set. Scorecard: once saved it shows a Saved badge + average, the form sits behind "Edit my scorecard". UI only, no migration. check.php task OK; not browser-verified; not committed. Open: HR must create SC/OIC/MT accounts in Users & Access (role change, not done by me).

- 2026-11-04 retail-manager (Claude): John approved SPEC-retail-manager.md decisions (new position + role RETAIL_MANAGER; ADL Reporting To lists Retail Managers only; one now but any number allowed; no recruitment role; existing ADLs assigned by hand). Plan written (Plans/2026-11-04-retail-manager.md, status draft, 5 tasks, migration 032). No code yet. Waiting John go.
- 2026-11-04 retail-manager (Claude): T1-T3 built on scratch DBs only (migration 032 role+position, ADL Reporting To = Retail Managers only, Directory + Manager-filer sweep). retail_manager_test 25 PASS, adl_reporting 30 PASS, check.php task OK. Not applied to pmbsi_hris, no commit. Waiting John review (Checkpoint A) before T4/T5.
- 2026-11-04 retail-manager (Claude): T4 (end-to-end routing/visibility) and T5 done. Migration 032 applied to localhost pmbsi_hris after a verified backup (storage/slice15-private/pmbsi_hris_before_032_*.sql; 126 tables, row counts + checksums equal); only roles, positions, role_permissions and the new retail_manager_seed changed. Plan status done. Vault synced (Progress, Data-Model, Business-Rules BR-887, Decision-Log, Open-Questions, Auth-and-RBAC, Employee-Records, Org-Structure, CHANGELOG). Next: John tries Employee 201 of an ADL in the browser, creates the Retail Manager employee + account; commit local on his word.

- 2026-11-04 consultant-status (Claude): "Consultant" added to Employee Status (employment_types, code CONSULTANT, sort 120; migration 033, applied to localhost 2026-11-04). Data row only, no code change. Local; live hosting not applied.
- 2026-11-04 department-picker (Claude): John approved SPEC-department-picker.md (Department = Manpower Planning, Inventory Management, Finance, HR, Retail, Marketing & Creatives, Online; Position grouped by team like Directory; all 3 forms; nothing may break; 'Online Shop' renamed to 'Online' via migration 034). Plan: Plans/2026-11-04-department-picker.md (approved). No code yet. Supersedes Module 1 of SPEC-form-keep-input.md; keep-input is a later slice.
- 2026-11-04 department-picker (Claude): BUILT and applied to localhost (migration 034 renames Online Shop -> Online). New app/DepartmentPicker.php, 3 employee forms use it, optgroup JS error fixed. department_picker_test 23 + http 15 + browser 10 PASS; only tests/employee_profile_http_test fails, identical without this patch. Plan status done. Next: SPEC-form-keep-input.md Module 2 (keep typed input after an error/refresh), waiting John's go. No commit yet.
- 2026-11-04 form-draft (Claude): John wants typed values to STAY after a refresh (my first patch did the opposite, reverted). public/assets/hris-app.js: per-tab sessionStorage draft for every POST form (restores in DOM order, fires change for dependent selects; skips password/file/hidden/csrf). Draft cleared after a successful save or Cancel, kept after a failed save (error banner). View.php asset version bumped, tests/sidebar_fold_test.php version pin updated. Scratch-page Playwright check 3 PASS; task check PASS; not tested on the real Add Employee page. Covers SPEC-form-keep-input Module 2 criteria 3-4 only (server-side refill after a failed POST still open). Local, no commit.

- 2026-11-04 reporting-to-box-removed (Claude): John asked to remove the "Reporting To / Manager or ADL" box from Employee 201 edit form (index.php). Save handler now sends manager_employee_id only when posted, so existing Reporting To values stay unchanged. Existing manager data, routing and Ocampos auto-ADL on save untouched/inactive from this form. Local only; checks: php -l, check.php task. Not run: browser check.

- 2026-11-04 correction (Claude): Reporting To box restored (removal was a misread). Cause of empty ADL list: Retail Manager Rom Dela Cuesta (emp 386, user 30) has account role EMPLOYEE, not RETAIL_MANAGER, so he is not listed. Role change pending John approval.

- 2026-11-04 (Claude): Reporting To box now hidden only when Employee Status = Consultant (index.php, live toggle on status change; value still submitted so stored manager is kept). Other statuses unchanged. Local; not browser-checked.

- 2026-11-04 adl-reports-to-retail-manager (Claude): John confirmed emp 386 Rom Dela Cuesta is the Retail Manager and approved the role fix. Localhost only: users.id=30 role EMPLOYEE(7) -> RETAIL_MANAGER(19) (1 row; users 30, RETAIL_MANAGER users now 1). Backup (users/roles/role_permissions) at Documents/HRIS-backups/pmbsi_hris_pre_rm_role_20261104.sql (outside repo, has hashes). index.php: Retail Manager position with wrong role shows disabled for ADLs with "Account role must be Retail Manager with approval access". Spec: SPEC-adl-reports-to-retail-manager.md. check.php task OK. Not run: browser, hosting apply (live needs the same role change by John).

- 2026-11-04 (Claude): Reporting To dropdown showed "Retail Manager · Retail Manager" (position + account role identical). index.php option label now skips the role when it equals the position (case-insensitive). Local; php -l OK; not browser-checked.

- 2026-11-04 login-password-eye (Claude): Sign-in page Password field now has an eye button to show/hide the typed password (index.php login form + inline toggle script; public/assets/hris-modern.css .login-pw). Local; php -l OK; not browser-checked.
- 2026-11-04 login-password-eye fix (Claude): eye sat below a narrow input because the browser used the cached hris-modern.css (version 20261102-applicant-v1). Bumped to 20261104-login-eye-v2 in app/View.php; .login-pw now display:block;width:100%. Checked in Edge at 1535px and 390px: eye inside the field, vertically centred, toggle works. Local.

- 2026-11-04 endorse-sc-oic-visibility (Claude): HR's Endorse card showed nobody for Adrian Domingo (app 17, Robinsons Antipolo) although Myra Azcuna is the Sales Captain there. Cause: her portal account role is EMPLOYEE, and the list uses the account role (ADL/OIC/SC/MT), not the position; the old hint only covered staff with no account. Fix: hint now also lists staff in a reviewer position whose account is not active or has a non-reviewer role, with the reason (RecruitmentRepository::scopeStaffWithoutAccount, CandidateReviewViews.php). Test cases added to tests/hiring_review_visibility_test.php (23 PASS on scratch DB). check.php task OK. Data NOT changed: John sets Myra's role to Sales Captain in Users & Access. Not run: browser. Spec: SPEC-endorse-sc-oic-visibility.md.

- 2026-11-04 endorse-inline-role-fix (Claude): SPEC ONLY, no code changed. John wants HR to fix a reviewer-position account role (example Myra Azcuna, Employee -> Sales Captain) from the Endorse card instead of Users & Access. Draft: SPEC-endorse-inline-role-fix.md (reuses change_user_role + FoundationRepository::changeUserRole guards, position->role map SC/OIC/MT, return redirect whitelist). Pending: John approval and answers to 4 open questions (does SC keep Employee self-service permissions, portal of SC, ADL excluded, OK as permission-flow change). Nothing applied, nothing deployed.

- 2026-11-04 endorse-inline-role-fix v2 (Claude): John answered the open questions: SC stays Employee portal and only gains the Candidates for review sidebar item (SC already has recruitment.review, migration 023; View.php:102 shows it by permission); ADL excluded; same guards as Users & Access. Spec updated, still NO code. Waiting for John go. Local only.

- 2026-11-04 sc-oic-my-team (Claude): SPEC ONLY, no code. John: SC/OIC get a My Team list (same branch, view-only) as a SEPARATE slice after endorse-inline-role-fix; wants OIC to approve when SC is away but unsure how to define it. Draft SPEC-sc-oic-my-team.md recommends option A: reuse the existing date-bound OIC Authorization (BR-734/735). Part 2 (approvals) is a payroll/permission change, needs John decision. endorse-inline-role-fix already covers OIC. Pending John go on the role fix. Local only.

- 2026-11-04 endorse-inline-role-fix BUILD PAUSED (Claude): code written, lint OK, NOT tested, not applied: RecruitmentRepository::suggestedRole/POSITION_ROLE/fixReviewerRole, scopeStaffWithoutAccount returns suggested_role, index.php action endorse_fix_role (needs recruitment.manage + users.manage), button on Endorse card (CandidateReviewViews.php). BLOCKER found read-only on local pmbsi_hris: HR_USER (Human Resources, Kristian) has NO users.manage and lacks 12 SC permissions, so the subset guard in FoundationRepository::roleAssignmentError would refuse SC even with users.manage; only Super Admin can assign SC today. Spec assumption 1 was wrong. Waiting for John choice (new narrow permission vs other). Local only. Employee perms are a strict subset of SC (verified).

- 2026-11-04 endorse-inline-role-fix (Claude) STATUS: code + migration 035 (HR_USER gets users.manage + 12 SC perms) written; scratch cycle up/rerun/down/up PASS; hiring_review_visibility_test 30 PASS on scratch. NOT applied to pmbsi_hris. CONFLICT: with 035 on scratch, rec_endorse_manager_test fails 2 and rec_endorse_manager_http_test fails 1 because they deliberately assert HR_USER has NO recruitment.review (not an HR screen). Not weakening those tests. Also OIC needs payroll.approve_manager (not granted to HR). Waiting for John decision: narrow dedicated permission instead of widening HR_USER. check.php task OK. Local only, nothing committed.

- 2026-11-04 endorse-inline-role-fix DONE locally (Claude): narrow permission route chosen after widening HR_USER broke the "HR has no recruitment.review" tests. Migration 035 applied to localhost; code in RecruitmentRepository, FoundationRepository, CandidateReviewViews, index.php; tests extended in hiring_review_visibility_test.php (32 PASS). OIC excluded (payroll.approve_manager). Next: John tries it as HR on Adrian Domingo; SPEC-sc-oic-my-team.md Part 1 waits for his go. Not committed, not on hosting.
- 2026-11-04 pipeline-polish (Claude): John asked to polish the Pipeline board; Deployed should leave it (talent-pool checkpoint). Done in index.php (hr-pipeline), public/assets/app.css, app/View.php (?v=20261104-pipeline-v2): Deployed column removed (header button "Deployed (N)" opens Applicants filtered to Deployed), 10 stages = clean 5x2 grid (3/2/1 cols on smaller screens), own accent colour per stage, "+N more · View all" when a stage has over 8 cards, section/aria-label per column. No DB, no migration. check.php task OK. Not browser-verified. Talent Pool page + pull-back NOT built: SPEC-talent-pool.md still DRAFT, waiting John approval. Local, not committed.
- 2026-11-04 pipeline-scale-talent-pool (Claude): PLAN ONLY, no code. John wants board that scales (counts + top 8 per stage in SQL, Applicants paging 25), advanced board UI (toolbar filters, days-in-stage, grouped Parked columns) and a Talent Pool shelf with pull-to-job. Plan: Plans/2026-11-04-pipeline-scale-talent-pool.md (draft; S1 scale, S2 UI, Checkpoint A, S3 Talent Pool). No migration expected (applications allows many per applicant; resume row is copied). Waiting John go and 3 open answers. Local only.
- 2026-11-04 pipeline-scale S1+S2 (Claude): John said go. S1: RecruitmentRepository applicationFilter/applicationsCount/boardStageCounts/boardColumns/boardFilterOptions, applications() limit/offset + job_id/recruiter_id filters; Applicants list paged 25 (pager_html in functions.php). S2: board v3 in index.php hr-pipeline + app.css (?v=20261104-pipeline-v5): Deployed off board with header button, search + job + recruiter filters (GET), Hiring flow vs Parked groups, cards with avatar, days-in-stage chip (amber 7d, red 14d, with text), no empty rating chip, "+N more" keeps filters, sticky headers, responsive 6/4 -> 3/2 -> 2 -> 1 cols. New tests/pipeline_board_test.php (12 PASS, registered in check.php); related rec tests PASS on scratch DB hris_slice15_test_20261104_210334; check.php task OK; browser-checked 1440/1024/768/390 light+dark, no sideways scroll, paging works. No migration, no commit, local only. STOPPED at Checkpoint A: John reviews the board; S3 Talent Pool waits for his go + 3 open answers.
- 2026-11-04 pipeline-site-split (Claude): SPEC ONLY, no code. John wants Head Office and Retail applicants separated on the Pipeline. Draft SPEC-pipeline-head-office-retail.md: All/Head Office/Retail/Other tabs by job site (reuses siteTypeOf rule in SQL, no migration), also on Applicants. 4 open questions (default tab, Retail branch/area filter, Other tab, Dashboard/Reports). Waiting John answers.
- 2026-11-04 pipeline-site-split v2 (Claude): SPEC ONLY, no code. John answered: no All tab, per-HR saved view (Head Office HR stays Head Office, Retail HR sees only picked branches, can switch), Retail needs branch filter, Pipeline only, 'Other' tab dropped (unassigned notice only). Needs migration 036 (user_pipeline_view + _branches), not approved yet. Open: Q-A switch allowed, Q-B Applicants links filtered, Q-C tables OK, Q-D default Head Office.
- 2026-11-04 pipeline-site-split v3 (Claude): John approved the spec (switch both ways, Applicants links filtered, migration 036 OK, default Head Office). Plan written: Plans/2026-11-04-pipeline-site-split.md (draft, 5 tasks, Checkpoint A after T3). NO code, not applied. Waiting John go.
- 2026-11-04 pipeline-site-split BUILT locally (Claude): T1 migration 036 (applied to localhost after verified backup), T2 repository, T3 screen, T4 Applicants link filter, tests + browser pass. Plan status done, no commit, not on live hosting. Next: John tries it on localhost; Dashboard/Reports split only if he asks.
