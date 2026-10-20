---
title: Progress
type: plan
status: draft
tags: [plan, progress]
updated: 2026-10-17
---

# Progress

Tick as work lands. Add the commit hash or PR next to a ticked item. Slice definitions live in [[Build-Plan]].

## Decisions gate

- [ ] Q1 company model answered, [[ADR-002-Multi-Tenancy]] accepted
- [ ] Q2 stack answered, [[ADR-001-Tech-Stack]] accepted
- [ ] Q3 rate tables as config, [[ADR-003-Payroll-Engine-Approach]] accepted
- [ ] Q4 cut-off calendar answered
- [ ] Q5 attendance source answered, ADR-004 written

## Slices

- [ ] SLICE-01 Walking skeleton
  - [ ] Repo, CI, test harness
  - [ ] Migrations and seeders
  - [ ] Login, sessions, lockout
  - [ ] Roles and scope
  - [ ] Audit log append-only
  - [ ] Department and employee create
  - [ ] Deployed from clean checkout
- [ ] SLICE-02 Org and 201 file
- [ ] SLICE-03 DTR capture
- [ ] SLICE-04 DTR review, corrections, lock
- [ ] SLICE-05 Leave
- [ ] SLICE-06 Rate tables and pay rules
- [ ] SLICE-07 Payroll compute
- [ ] SLICE-08 Register, payslip, release, reversal
- [ ] **MVP exit criteria met** (see [[Roadmap]])
- [ ] SLICE-09 Self-service portal
- [ ] SLICE-10 Notifications
- [ ] SLICE-11 Statutory reports
- [ ] SLICE-12 Recruitment to onboarding
- [ ] SLICE-13 Offboarding, final pay, 13th month
- [ ] SLICE-14 Performance
- [x] SLICE-15 Ticket core + Payroll Concern (2026-10-20; both approval checkpoints completed)

## Brownfield inventory (fill per slice)

| Slice | Existing code found | Keep / adapt / replace | Note |
|---|---|---|---|
| 01 | | | |

## Log

| Date | Entry |
|---|---|
| 2026-10-17 | Vault created; no code written. |
| 2026-10-17 | Patch (not a slice): Ocampo's roster import. Migration `20261017_lee_code_payroll_group.sql` adds `employees.lee_code` (unique) and `payroll_group`; roster parser reads the Ocampo's list (Bio Code = Employee Code, PMBSI code kept in `roster_original_code`, NBSP cleaning). John imported the 36 rows through the UI; DB backup taken before the migration. SLICE-01 stays paused. |
| 2026-10-17 | Patch (not a slice): Ocampo's Head Office sections. Migration `20261017_oc_head_office_sections.sql` adds `directory_sections` (scoped by company_group + site_type) with Inventory Management, Ocampos Production and Goldsmith for OC + HEAD_OFFICE only, and nullable `employees.directory_section_id`. The Section field on the Employment tab shows only for Ocampo's + a Head Office site; the server rejects out-of-scope values and clears the section when the employee leaves OC Head Office. Shown in the directory row. Departments and positions untouched; the shared Head Office branch (id 63) is kept. DB backup taken first, migration applied to live (re-run verified). `tests/oc_head_office_sections_test.php` 41/41 and `adl_reporting_test.php` 30/30 on a scratch copy. Dry-run (`scripts/oc_head_office_section_dryrun.php`) suggests a section for 3 of 8 OC Head Office employees; **none assigned**. Position-to-section hints are NEEDS HR CONFIRMATION (Inventory Management staff positions). Position near-duplicate merging already exists in `EmployeeRosterParser::LEE_POSITIONS` (earlier patch), not touched here. |
| 2026-10-17 | Follow-up to the Ocampo's Head Office sections patch, decided by John: only **Ocampos Production** is a section. Inventory Management is a shared department (Sw Head Office uses it) and Goldsmith is a position under Ocampos Production, so both sections were retired (removed if unused, deactivated if held). Migration also moves the `Goldsmith` position from Inventory Management to Production (only while it is still there) so the dropdown reads "Goldsmith · Production"; the roster parser maps Goldsmith to Production for future imports. Employees untouched: the Goldsmith employee (id 351) still has department Inventory Management, HR to decide. DB backup taken first, applied to live twice. Tests 44/44 (includes upgrade from the 3-section state) and ADL 30/30. Dry-run: 3 of 8 OC Head Office employees suggest Ocampos Production; none assigned. |
| 2026-10-17 | Patch (not a slice): Retail "Reporting To" = ADL only. Server-side rule in `EmployeeRepository` (Retail = department code RETAIL; ADL = same rule as the directory), dynamic dropdown on the Employment tab, Ocampo's auto-fill from the ADL who holds the Ocampos area (data-driven, no name hardcoded), HR-only change (`employees.manage`). `tests/adl_reporting_test.php` 30/30 on a scratch copy. **Backfill not applied**: dry-run shows 268 Retail employees unassigned, 0 settable until an ADL is given the Ocampos area in Employee 201. ADLs have no login yet. |
| 2026-10-17 | Patch (not a slice): Head Office team grid no longer shows the synthetic "<Department> employees" card ("Employees assigned directly to …") when 0 employees are assigned directly. Applies to every main department and both groups (Finance, Inventory Management, Manpower Planning; Marketing/Online have no team cards). The card still appears when the direct count is above 0 so those employees stay reachable. One condition in `EmployeeDirectoryService` (team-card loop); the "N teams" badge counts cards so it follows automatically. No DB or data change. `tests/directory_direct_card_test.php` 18/18 and `adl_reporting_test.php` 30/30 on a scratch copy; All employees stays 363. Not touched, for HR to decide: 3 Department Managers (Finance, Manpower Planning, Inventory Management) and the Goldsmith (OMOA, department Inventory Management) are direct-assigned but are listed under Managers / OC Retail, not in these cards. |
| 2026-10-18 | Patch (not a slice): Biometric import hardening, **Yugto A (read-only diagnosis) + Yugto B (data fix)**. A: account 17845 (user 10 → employee 1) was already linked and mapped, and "My Logs" returns 33 logs for cutoff 47 when simulated; 14 biometric IDs had no owner in `employees` (9 plus 414 and the ambiguous 18496 for cutoff 47, and 17394, 18326, 1366 for cutoff 1). Gaps found: auto-map by Employee No only runs for the file being committed (earlier imports stay UNMAPPED); "Already imported" blocks by file hash; a row beyond the cutoff rejects the whole file; `saveMapping` aborts on a FINALIZED cutoff. B: DB backup (`~/hris-db-backups/pmbsi_hris_before_stageB_20261018_104616.sql`), then `saveMapping()` for 1295, 1648, 1699, 18338, 18372, 18482 and the leftover 1275 row (all exact Employee No matches, applied under the SUPER_ADMIN account, user 7). Cutoff 1: +196 punches (3515 → 3711), UNMAPPED rows 674 → 478, attendance_daily rebuilt. The 14 IDs without a confirmed owner were **not touched** (NEEDS HR CONFIRMATION). Yugto C (robust matching, I-map screen, reprocess, per-row cutoff, AMBIGUOUS handling, 2-punch display fix) and D (delete/replace) not started. |
| 2026-10-18 | Patch (not a slice): Check Attendance shows scattered punches correctly (part of the Yugto C "2-punch display fix"). Cause: biometric exports carry generic IN/OUT, so `PayrollCalculator::assign()` finds 3 valid mappings for 2 punches (and 2 for 1 punch) and nulls every slot, giving `AMBIGUOUS_PUNCHES`, "Missing" and "Check TA". Fix is **display only**: new `AttendanceSlotDisplay` puts every saved punch of the day (any file, any import order) in Time In / Break Out / Break In / Time Out by time, empty slots stay Missing, nothing is guessed; more than 4 punches is flagged "More than 4 logs" and all are listed in Details (slots left empty). `EmployeeAttendanceService::cutoff()` also stops trusting an `attendance_daily` row that lacks a saved punch. `attendance_daily`, `assign()`, late/undertime/OT and every use of AMBIGUOUS_PUNCHES in payroll/export are **unchanged** (the day stays state ISSUES in Payroll until all four slots exist). Auto-rebuild: new `rebuildAffected()` after `commitImport`, `saveMapping` and `rematchImportedRows` rebuilds the affected employees in every open cutoff covering the punch day (or the day before, for night shifts), even when the file was filed under another cutoff; FINALIZED cutoffs are skipped and logged, never changed. Re-importing a punch does not double (existing `UNIQUE(employee_id, punched_at, punch_status)`). DB backup `~/hris-db-backups/pmbsi_hris_pre_slot_patch_20261018_112458.sql`; no live data rebuilt or changed (punches still 3711). `tests/attendance_slots_test.php` 23/23 on a scratch copy (2/3/4/5 punches, 1 punch, no punch, both import orders, file under another cutoff, finalized cutoff; night shift covered in the pure placement test only), and the 3 existing tests still pass. Live read-only check: employee 17845 Oct 5 = 09:06 / 13:10 / Missing / Missing, Check TA. **Open for John:** with 2 punches the 2nd is shown as Break Out whatever its IN/OUT label (unless the device names the slot), so a full day with missing lunch punches reads as Break Out 6 PM + Time Out Missing until TA/the other file arrives. |

## SLICE-15 checkpoints

- [x] Step 1 (2026-10-20): generic ticket core migrations 001/002, effective-dated proven-only backfill, and HR-only bulk approver assignment screen. Applied to localhost after verified mysqldump restores and isolated up/re-run/down/up tests. Separate new request path; existing Payroll Ticket Review and TA/OB/OT/leave unchanged.
- [x] Step 2 (2026-10-20): John approved Step 1 and the employee UI placement. Migration 003 applied after verified backup/restore; new HR Concerns Home tile and sidebar Requests entry, My HR Requests/type selector, labeled Recent requests, Manager/ADL inbox and HR Payroll Concern queue. HO → Manager → HR, Branch → ADL → HR and late → HR/manual adjustment chains completed in the browser against an isolated database. Existing four tiles, routes, Payroll Ticket Review and TA/OB/OT/leave preserved. Stopped after Step 2 as requested.

Step 2 validation: Payroll Concern 71/71; core 97/97; assignments 40/40; legacy payroll/leave flows 16/16; ADL 30/30; HO sections 44/44; directory 18/18; attendance 23/23; Payroll Concern browser 23/23; assignment browser 11/11. Verified migration 003 up/re-run/down/up and refusal of destructive down after use. All 84 existing tables other than the seeded `ticket_types` table retained their schema/data hashes during apply. No live test accounts or tickets. See [[Slice-15-Step-2-Results]].

Step 1 validation: core 97/97; assignments 40/40; legacy Payroll Ticket Review/TA/OB/OT/leave flows 16/16; existing ADL 30/30, HO sections 44/44, directory 18/18, attendance 23/23; browser 11/11 (bulk preview/save for two employees, reload, replay, CSRF, self-approval, HR-only access, mobile). See [[Slice-15-Step-1-Results]]. Live backfill: 0 proven assignments; all 363 active employees unresolved (369 total, 6 inactive). No site approvers are configured; the two explicit Reporting To entries do not resolve to an active matching approval account. HR can resolve these through **HR Request Setup → Ticket Approvers → Unresolved report**. Company legal entity remains PMBSI as confirmed; tickets snapshot Company Group/Company Name separately. Backups and employee-containing artifacts are in ignored, Apache-denied `storage/slice15-private/`; no employee data was committed. Unrelated workspace changes preserved.

## Related

[[Build-Plan]] · [[Claude-Code-Brief]] · [[Open-Questions]] · [[Decision-Log]]

- 2026-10-20 Stage B / ATM+ID committed (e70e210). Migration 004 applied after two verified mysqldump restores; up/rerun/down/up and used-data rollback guard passed. Service 21/21, Payroll Concern 71/71, core 97/97, assignments 40/40, legacy TA/OB/OT/leave 16/16, browser 4/4. Existing business rows unchanged. Includes previously untracked Slice-15 engine dependencies; unrelated workspace files excluded.

- 2026-10-20 Stage B / Uniform committed. Migration 005 applied after verified backup/restore; up/rerun/down/up and used-data rollback guard passed. Cumulative services 30/30, Payroll Concern 71/71, core 97/97, assignments 40/40, legacy 16/16, browser 5/5. Company-label/target-branch/status filtering checked; existing business rows unchanged.

- 2026-10-20 Stage B / NetSuite committed. Migration 006 backup/restore, up/rerun/down/up and used-data guard passed; services 44/44, Payroll Concern 71/71, core 97/97, assignments 40/40, legacy 16/16, browser 6/6. HR remains final approver; explicit Accounting membership grants completed-ticket follow-up only. Seed link results are available in Accounting Members; Jeniffer requires manual HR linking. Existing business rows unchanged.

- 2026-10-20 Stage B / Manpower committed. Migration 007 applied after verified backup/restore, up/rerun/down/up and used-data guard. Cumulative services 54/54, Payroll Concern 71/71, core 97/97, assignments 40/40, legacy 16/16, browser 7/7. HO Manager/Branch ADL gate, frozen resubmission route and served date verified; Recruitment manpower_requests unchanged.

- 2026-10-20 Stage B / Branch Transfer committed. Migration 008 created employee_placements (absent live); current placements observed with unknown start dates NULL, without invented history. Fresh verified backup/restore and up/rerun/down/up/used-data guard passed. Services 69/69, Payroll Concern 71/71, core 97/97, assignments 40/40, legacy 16/16, browser 9/9. Verified locked cutoff recheck, future In Progress, atomic/manual approver completion, retry uniqueness, no overlaps, file-for-others identity and no self-approval. Employee company/legal entity fields remain unchanged; SW/OFJI labels only.

- 2026-10-20 Stage B / Incident + forms committed; all six approved slices completed in order. Migration 009 applied after verified backup/restore. Final cumulative services 92/92, Payroll Concern 71/71, core 97/97, assignments 40/40, legacy payroll/leave 16/16; Payroll Concern browser 23/23 and new-type browser 11/11; ADL 30/30, HO sections 44/44, directory 18/18, attendance 23/23. All six migrations restore original normalized definitions/row hashes on isolated up/rerun/down; used-data down refused without changes. All five blank forms print as one A4 page and were visually checked. Incident staff comments/events and attachments remain scoped; Payroll Concern selection retention and HR-stage cancellation ownership verified. Seven Accounting seed numbers lack active linked accounts; Jeniffer remains for manual HR linking. Private evidence: storage/slice15-private/stageb-4.json through stageb-9.json. Full details: [[Slice-15-Stage-B-Results]].
