---
title: Open Questions
type: plan
status: review
tags: [product, decisions, needs-verification]
updated: 2026-10-26
---

# Open Questions

Decisions John needs to make. Each is blocking something concrete. When one is decided: tick it, record the answer inline, and if it is architectural, promote it to an ADR in [[Decision-Log]].

> [!warning] These are not rhetorical
> Several of these silently change the data model. Deciding them late means a migration. The top five block [[Build-Plan]] SLICE-01.

## Blocking — decide before writing schema

- [ ] **Q1. One company or many?** Will this system ever hold a second legal entity, or client companies as in an agency/manpower setup? The existing codebase already has `clients` and `branches`, which suggests yes. This determines whether every table carries a tenant key. → [[ADR-002-Multi-Tenancy]], [[Data-Model]]
- [ ] **Q2. Keep the PHP/MySQL codebase or rewrite?** There is a working modular monolith already. Rewriting costs months; keeping it constrains tooling. → [[ADR-001-Tech-Stack]], [[Architecture]]
- [ ] **Q3. Are statutory rates config rows or code constants?** Strongly recommended as effectivity-dated config, because the whole reproducibility goal depends on it. Needs an explicit yes. → [[ADR-003-Payroll-Engine-Approach]], [[Payroll]]
- [ ] **Q4. Pay frequency and cut-off calendar.** Semi-monthly 1–15 / 16–end, or 11–25 / 26–10? Is the cut-off the same for all employees, or different for office vs site staff? Does pay date fall in the same month as the cut-off? This shapes the DTR lock, the tax table used, and 13th month computation. → [[Payroll]], [[Attendance-and-DTR]]
- [ ] **Q5. How does attendance get in?** Live integration with the biometric device, scheduled export file dropped in a folder, or manual upload by the payroll officer? Which device make/model, and what does its export actually look like? Everything in [[Attendance-and-DTR]] downstream depends on this. → needs ADR-004

## Important — decide before the payroll slice

- [ ] **Q6. Which leave types exist, with what rules?** Statutory SIL only, or company VL/SL on top? Accrual monthly or granted annually in advance? Carry-over cap? Which types convert to cash? Is SL convertible? → [[Leave-Management]], [[Business-Rules]]
- [ ] **Q7. Who is exempt from OT and night differential?** Managerial and supervisory employees typically are, but the boundary is a company policy call and must be a field on the employee record, not a guess. → [[Employee-Records]], [[Business-Rules]]
- [ ] **Q8. Monthly-paid or daily-paid employees — or both?** This changes the divisor used to derive a daily and hourly rate, and whether unworked holidays are already included in the monthly salary. It is the single most common source of PH payroll disputes. → [[Payroll]], [[Business-Rules]]
- [ ] **Q9. How are corrections after release handled?** Void and reissue the payslip, or post an adjustment in the next period? Released runs should be immutable, so this needs an answer. → needs ADR-009, [[Payroll]]
- [ ] **Q10. What exactly must appear on the payslip?** Required lines, YTD figures, employer share shown or not, language, and whether a PDF must be downloadable. → [[Self-Service-Portal]], [[Payroll]]
- [ ] **Q11. Loans and cash advances.** Are SSS/Pag-IBIG loans and company advances tracked with amortisation schedules inside this system, or entered as a flat deduction per period? → [[Payroll]]
- [ ] **Q12. De minimis and allowances.** Which allowances does the company actually give, which are taxable, and which fall under de minimis ceilings? → [[Payroll]], [[PH-Labor-and-Payroll-Notes]]

## Operational

- [ ] **Q13. Where does this run?** Stays on XAMPP on an office machine, moves to a VPS, or hosted locally with external access for field employees? Drives the whole security posture. → [[Architecture]], [[Security-and-Compliance]]
- [ ] **Q14. Backup and recovery.** Who takes backups, how often, tested how? Payroll data loss is unrecoverable by hand. → [[Security-and-Compliance]]
- [ ] **Q15. Do employees get accounts?** All 300, or only office staff? If field employees have no account, HR files leave on their behalf (US-406) and self-service value drops. → [[Self-Service-Portal]], [[Auth-and-RBAC]]
- [ ] **Q16. Email sending.** Is there an SMTP account or transactional email service available for notifications and payslip alerts? → [[Notifications]]
- [ ] **Q17. Data migration.** What historical data must come in — current employees only, or past payroll history too? Partial-year history matters for 13th month and tax annualisation. → [[Employee-Records]], [[Payroll]]
- [ ] **Q18. Document storage.** Scanned 201 documents: filesystem, database, or object storage? With what retention and access logging? → needs ADR-006, [[Security-and-Compliance]]

- [x] **Q28. TC access.** Team Coordinator is assumed to have the same access as ADL (files Manpower/NetSuite, approver-capable). Confirm. → [[Self-Service-Portal]] — **Answered 2026-10-24 (John):** yes; migration 011 gives TC `payroll.approve_adl`, same grants as ADL. COORDINATOR is a different (recruitment) role.
- [ ] **Q29. Staff lost Manpower and NetSuite.** Migration 010 gave the `EMPLOYEE` role only the five staff types, so plain employees can no longer file Manpower or NetSuite Concern (by design). Confirm no staff member needs them before ADL/SC/OIC/TC roles exist. → [[Self-Service-Portal]]
- [ ] **Q30. Approvers for unassigned employees.** 363 active employees still have no Manager/ADL approver for Payroll Concern. Once HR creates the ADL/TC/OIC/Manager accounts and links them to employees, assign them in Ticket Approvers. Who does this, and by when? → [[Auth-and-RBAC]]
- [ ] **Q31. Super Admin from the UI.** Super Admin cannot be assigned from the user screen by anyone (BR-122). Is a DB-level procedure acceptable for the rare new Super Admin, or is a guarded UI path wanted? → [[Auth-and-RBAC]]
- [x] **Q32. Override semantics.** Closed 2026-10-24: extra viewers and manual leads were removed; viewers are automatic (BR-730). → [[Self-Service-Portal]]
- [ ] **Q33. Branch lead data.** (Updated 2026-10-24 after 013.) Viewers now come from positions and accounts: 23 of 63 staffed retail branches have no Sales Captain on record, 39 have one without a usable account, 1 branch has two. Who supplies the missing Sales Captains and creates the accounts? → [[Org-Structure]]
- [ ] **Q34. ADL setup.** (Updated 2026-10-24 after 012: 7 of 8 ADLs still have no account; 1 ADL now has Assigned areas, Ocampos.) Originally: 7 of 8 ADLs have no account, none has Assigned areas, 4 show branch Head Office, 3 active retail branches have no area. HR to complete before ADL coverage works. → [[Org-Structure]]
- [ ] **Q35. Attachments for viewers.** Viewers see no attachment list and cannot download (strict reading of read-only). Confirm, or allow download. → [[Self-Service-Portal]]
- [x] **Q36. Hazelyn Cabrera as OMOA lead.** Closed 2026-10-24: she is the OMOA Sales Captain by position with an active account, so she is a viewer automatically. → [[Org-Structure]]
- [ ] **Q37. Stale area on a non-ADL.** Employee 1651 (Sales Captain, no account) carries the Ocampos area in Assigned areas for ADL. It has no effect (only an ADL's areas count), but it looks like a mistaken entry; HR to clear it or confirm. → [[Org-Structure]]
- [ ] **Q38. Higher role, exactly.** OIC Authorization blocks any nominee whose account is not on the plain Employee role (and any Sales Captain, ADL or OIC by position). Is that too strict (for example, may a Team Coordinator act as OIC)? → [[Auth-and-RBAC]]
- [ ] **Q39. Who assigns the OIC role.** The Users screen needs `users.manage` (Super Admin, PMBSI Administrator); HR_USER does not hold it, and a non-Super-Admin cannot assign a role whose permissions they do not hold. Should HR be able to assign OIC? → [[Auth-and-RBAC]]
- [ ] **Q40. OIC length and early end.** No maximum period and no early-end button; HR can only wait for the end date or change the role in Users. Needed? → [[Self-Service-Portal]]
- [ ] **Q41. ADL role permissions.** The live ADL role holds `ticket.file_for_others` and `tickets.approvers.manage`. OIC Authorization treats an employee-portal ADL as area-scoped, but the role itself looks broader than the vault says. Confirm. → [[Auth-and-RBAC]]

## Compliance

- [ ] **Q19. Is there a designated Data Protection Officer and a privacy notice?** RA 10173 obligations attach to the company, and the system must support consent capture and data-subject requests. → [[Security-and-Compliance]]
- [ ] **Q20. Retention periods.** How long are payroll records, DTRs and 201 files kept, and what happens at the end? → [[Security-and-Compliance]]
- [ ] **Q21. Which government reports are actually filed, and in which format?** Agencies accept specific file layouts or portal uploads, and these differ by employer size. → [[Reports-and-Analytics]]

## Statutory figures to verify

- [ ] **Q22.** Confirm current SSS contribution schedule and MSC brackets, with effectivity date and circular number.
- [ ] **Q23.** Confirm current PhilHealth premium rate, floor and ceiling.
- [ ] **Q24.** Confirm current Pag-IBIG contribution rates and the compensation cap.
- [ ] **Q25.** Confirm the current BIR withholding tax tables for each payroll frequency in use.
- [ ] **Q26.** Confirm the de minimis benefit ceilings and the 13th-month/other-benefits tax-exempt ceiling.
- [ ] **Q27.** Confirm current night differential percentage, OT multipliers and holiday pay rules against the Labor Code and the latest DOLE handbook.

- [ ] **Q42.** A Head Office employee's approver assignment (Ticket Approvers) can name a Manager different from Reporting To. Viewing follows Reporting To only (BR-737); approving follows the assignment, then Reporting To. Confirm HR is happy for the two to differ, or whether Ticket Approvers should show a warning when they do. (2026-10-24, assumed: they may differ.)
- [ ] **Q43.** "Manager" for BR-738 means the filer's account holds the `MANAGER` role. A Head Office head with another role (ADL, OIC, TC) still has a Manager above them. Confirm if other roles should also go straight to HR. (2026-10-24, assumed: MANAGER role only.)
- [ ] **Q44.** COE company name: {{company}} defaults to the employee's legal entity (PMBSI for most). Set the full registered name under COE Template if the certificate needs it.
- [ ] **Q45.** 83 of 369 employees have no hire date; their COE cannot be served until Employee 201 is completed (the preview names the gap).
- [ ] **Q46.** No salary rate exists in `payroll_employee_rates` (0 rows), so Include salary cannot be used until HR enters rates (Payroll).
- [ ] **Q47.** COE signatory name/title and any letterhead image are not set; they print blank until HR fills COE Template (images are not supported yet; put the letterhead in the .docx itself).
- [ ] **Q48.** Merge later: the dead employee-dashboard tile "Request COE" (index.php) and the Offboarding COE document list (`separation_documents_issued`, planned) overlap with this request type.
- [ ] **Q49.** Report access: granted to HR_USER, HRIS_ADMIN, HR_TIMEKEEPING. Add HR Leave or Payroll roles in Roles if they should see it.
- [ ] **Q50.** Setup health in the report needs `tickets.approvers.manage`; HR users without it see a notice instead of those numbers.
- [x] **Q51. Incident feed readers.** Answered by the Incident Report module decision (2026-10-25): the reporter, HR reviewers not named on the case, participants HR included and CCs the reporter picked read a case; viewers, Accounting and `ticket.sensitive.view` holders do not (BR-769). The permission stays in the database but opens nothing. → [[Employee-Tickets]]
- [ ] **Q52. HR filing OIC Authorization.** `ticket.file.oic_authorization` is held live only by ADL and SC; HR_USER and HRIS_ADMIN hold `ticket.file_for_others` but not that permission (checked 2026-10-25). Can HR file one for a branch, or is it ADL/SC only? → [[Self-Service-Portal]]
- [ ] **Q53. Database engine.** The live database is MariaDB 10.4.32 (XAMPP), while ADR-001, the old root docs and Data-Model assumed MySQL 8. Pick the production engine and version before hosting (see Q13) and re-run the migration round-trips on it. → [[Data-Model]]
- [ ] **Q54. Stale payroll cutoff statuses.** Calendar status is not advancing by itself (cutoff 1 still OPEN after its end date, cutoff 47 UPCOMING after its start, seen 2026-10-25). Payroll Concern filing no longer depends on it (BR-766) but other screens may. → [[Payroll]]
- [ ] **Q55. Is the last punch a Break Out or a Time Out?** For a day with only 12:17 PM IN and 5:08 PM OUT, both screens place 5:08 PM in Break Out (plain time order) and ask for Break In and Time Out. It may really be Time Out. Changing the placement rule affects payroll, so it was not changed; John to decide (2026-10-25). → [[Attendance-and-DTR]]
- [x] **Q56. Incident categories.** Decided under John's delegation 2026-10-25: Workplace conduct, Harassment or discrimination, Safety and health, Property or equipment, Attendance-related, Other (Other needs an explanation). Labels for sorting only; changing the list is a code edit. → [[Employee-Tickets]]
- [ ] **Q57. Reporter-picked Involved and Witness.** Assumed: they are recorded for HR but cannot read the case until HR includes them; only a picked CC reads. Confirm. → [[Business-Rules]] BR-770
- [ ] **Q58. Reporter identity.** Everyone who can read a case sees the reporter's name, including an Involved person HR includes. Should the reporter be hidden from Involved and Witness people? → BR-773
- [ ] **Q59. NEEDS VERIFICATION: retention, confidentiality and due process.** How long incident and disciplinary records are kept, who may see them, and what due-process steps the company and the law require. Nothing in the code sets a retention period or a legal rule; closed cases simply stay. John and HR to confirm, then record in [[Security-and-Compliance]]. → [[Employee-Tickets]]
- [ ] **Q60. Filing without an Employee 201 link.** Checked live read-only on 2026-10-25: every role that can file HR requests already holds `ticket.file.incident` (SUPER_ADMIN passes by bypass), so no grant migration was needed. What still blocks filing is the account having no (or two) active linked Employee 201 records: the File an Incident page now says so plainly. Live had 12 active accounts with no active employee record. Should HR link them, or should accounts without an employee record be able to file (the case is filed under an employee record today)? Client-portal accounts are excluded from filing and from the people picker. After HR removes a person from a case they no longer read it (assumed). → [[Employee-Tickets]]

> [!warning] NEEDS VERIFICATION
> Q22–Q27 are all deliberately unanswered in this vault. No figure for any of them appears anywhere as fact. Each must be confirmed against the issuing agency's current circular, recorded with its effectivity date in [[PH-Labor-and-Payroll-Notes]], and entered as a config row — never as a constant in code.

Related: [[Decision-Log]] · [[Roadmap]] · [[Business-Rules]] · [[PH-Labor-and-Payroll-Notes]] · [[Start-Here]]
- [x] **Q61. HR cancelling a case and HR names.** Assumed: HR dismisses (with a reason) instead of cancelling a Submitted case, and the handling HR reviewer is named on updates and messages that every reader sees. Confirm, or ask for HR to appear only as "HR". → [[Business-Rules]] BR-780, BR-781
- [x] **Q62. Darker light-theme hue tokens.** Light chips, badges and alerts measure 4.1-4.4:1 (AA needs 4.5:1). The fix is darker `--hris-success/info/danger/warning` (suggested #137a50, #2560d6, #c2364a, plus an amber), which also drive buttons, alerts and charts. Confirm, then a separate patch. → [[Design-System]]
- [ ] **Q63. Head Office Creatives/MIS biometric file.** Cutoff 2026-09-19 to 2026-10-03: the uploaded biometric PDFs have no punches for Creatives (9) and MIS (5). Does the HO file still need uploading, or are they exempt from biometric? Code is fixed; only the data question remains. → [[Business-Rules]] BR-783, [[Attendance-and-DTR]]

- Q64 (2026-10-28): "Human Resources Assistant" is the only active HR-type position; it fails the PMBSI exact-alias rule (HR / Human Resource / Human Resources) and the 2LOG HR exclusion, so nobody can be assigned it. Add it as a PMBSI position? Owner: John.

- 2026-10-28: Should "Human Resources Assistant" count as a PMBSI position, or will John add a plain "Human Resource" position? (John is adding the latter.)

- Q65 (2026-10-29): With "Include someone" removed from the IR case page, HR cannot re-add a person after "Remove" in People on this case. Keep Remove, or remove it too? Owner: John.
- Q66 (2026-10-29): `incident_case_test.php`, `incident_case_ui_test.php` and `incident_browser_setup.php` fixtures lack `nature`, `location_type`, `immediate_head`, so the suites crash before any assertion. Update fixtures? Owner: John.

## 2026-10-31 - My Profile open points
- Old HRIS fields not stored in employees (religion, blood type, mother maiden name, number of children, tel/fax, preferred contact, city/province/zip, address line 2, bio code): add only if John approves a schema change.
- Payslip, Deductions, Additional, Downloads, FAQs are Soon.
- Should HR roles get Send Request / DTR / Leave links (they hold none of those permissions)? Left unchanged.
- Document thumbnails are the original image (lazy-loaded); add a server thumbnail step if large scans feel slow (GD is not enabled on local PHP).
- Live: employees.view_self for EMPLOYEE verified on localhost only.

- Document thumbnails need PHP GD on the live host (extension=gd); without it the grid serves the original images.

- Reveal rate limit / step-up re-authentication and a CSP sandbox for inline PDFs: not done (decide if the threat model includes shared workstations). Global verify_csrf() accepts an empty token when the session has none (pre-existing).
- My Profile JavaScript has no automated test; empty-state wording for HR/admin accounts with no employee row still says Contact HR.

- Lead notifications: a Retail branch with no ADL/area or a Manager with no account notifies nobody (already shown in the viewer report). Follow-up notifications to HR have no sidebar count (only the bell). Browser check of the badge/button not done.

- Q64 (2026-11-02): Role clean-up patch: remove HR Leave, HR Timekeeping, Recruiter and Payroll roles (users on them: Recruiter 2, HR Leave 1, Payroll 2; Recruitment Manager 1 not mentioned). Needs a permission diff against Human Resources, a reassign migration and a guarded down script. Owner: John. Not started.
- Q65 (2026-11-02): Filing the forgotten-password request needs a signed-in session; an unauthenticated request form was not built (abuse risk). The login page says to ask HR. Revisit if employees cannot reach HR.
- Q66 (2026-11-02): The 20 existing accounts have no stored password copy until reset; no bulk backfill is possible (hash only).
- Q67 (2026-11-02) BLOCKS the live run: 368 employees have a blank Company. Bulk gives them @pmbsi.com (Legal entity is always PMBSI, so this is only a default). Emails are locked, so a 2LOG person would keep a wrong domain. Options: pmbsi.com for all blank, map by group (SW / OC), or skip until Company is set. A screenshot showed 2LOG for one employee whose database Company is blank.
- Q68 (2026-11-02): The recruitment conversion form (Phase 2C) still offers Link later, existing or create account with a typed temporary password. Left alone; should it follow the auto-account rules?
- Q69 (2026-11-02): The live bulk run (about 350 real logins) is not done; it waits for a word from John after a fresh verified dump.
- Q70 (2026-11-02): users.remove is granted to HR_USER only. Should HRIS_ADMIN (Admin portal) also get it? Super Admin always can.
- Q71 (2026-11-02): A removed account keeps its password hash and encrypted copy in the row. Wipe password_enc on removal? Restore would then need a password reset.


- Q72 (2026-11-02): Position codes BS, SC and VS have no readable names in the app. HR types the title at Approve; do you want a code-to-name list?
- Q73 (2026-11-02): Approving completes the ticket, so the requester sees "Date served" on the approval date although nobody is hired yet. Keep, or relabel for this type?
- Q74 (2026-11-02): RECRUITER, COORDINATOR and RECRUITMENT_MANAGER have recruitment.manage but not tickets.review_hr, so they see the list but not Approve or Decline. Grant them the ticket review right (a permission change), or keep decisions to HR users?
- Q75 (2026-11-02): database/schema.sql still seeds the five demo clients plus demo requests, jobs and applicants for fresh installs, and index.php:652 still shows ops@primelogistics.com as the client-portal login hint (inactive on live now). Clean both?
- Q76 (2026-11-02): Careers and job pages will show the employer name HR types. Hide it for client-confidential hiring? Decide in rec-jobs.
- Q76 ANSWERED (2026-11-02, John): public pages show PMBSI only, never the employer (BR-854).
- Q77 ANSWERED (2026-11-02, spec Q-J5 approved by John): the public page shows a salary range only when both min and max are set, otherwise Salary negotiable (BR-855).
- Q78 (2026-11-02): closing dates use the PHP clock when saving and MySQL NOW() when reading; they agree on this laptop (both Asia/Manila). Confirm the hosting MySQL time zone before deploy.
- Q79 (2026-11-02): auto-closing a job when its request is fully filled belongs to rec-pipeline (it owns the filled count). Decide there.

- Q80 (2026-11-02): a recipient from an earlier round who never answered still counts as pending, so Not suitable from the new round alone will not return the candidate; HR can move the stage by hand. Decide whether HR should be able to remove a recipient.
- Q81 (2026-11-02): the rec-endorse-manager Mid interview type was added to the reviewer schedule form only; the HR schedule form still lists Initial, Final, Client and Technical. Align when rec-track-steps defines the interview rounds.

- Q82 (2026-11-02): interview-invite sends from the one company Gmail (SMTP App Password). A real Google Calendar / Meet invite through the Google API, and an email when an interview is rescheduled or cancelled (no reschedule action exists yet), are later steps.
- Q83 (2026-11-02): the Resend limit is per browser session and not stored; revisit if abuse from several sessions matters. The client-portal approval path still moves a candidate to Offer (client portal removal slice owns it).
- Q84 (2026-11-02): offer-rate-type uses 26 days/month and 8 hours/day (OfferRate::DAYS_PER_MONTH / HOURS_PER_DAY). Confirm the company rule (22 days? payroll hourly_divisor?) before relying on computed monthly amounts. Job Openings salary min/max are still monthly-only.
- Q85 (2026-11-02): working days are informational on the offer. Decide later whether the accepted offer days should feed the hired employee payroll divisor (payroll rule, needs approval). Q84 is partly answered: 22 and 26 are now both selectable; hours per day 8 still unverified.

## 2026-11-02 - hr-appearance-palettes
- Q: Palette hex values are estimates from the reference images (NEEDS VERIFICATION). John to confirm the look after the browser check; adjust palettes.css only, the contrast test guards readability.
- Q: Should Admin ever get the palettes? Closed for now: HR portal only (John, 2026-11-02).

## 2026-11-03 - rec-screening-score
- Q: Should the list show every scorer rating (HR vs ADL vs Manager) instead of only the latest? Not built; history is in screening_reviews.
- Q: Walk-in HR jobs with no Site type are HR-only. John to confirm HR should always pick one.
- Q: Old applicants rated before a later conversion rule: John Joshua (Converted) has no rating and stays locked; test on a new applicant.

- Q: Should the 3 old history rows that point at Applied (and any future legacy Applied application) ever be moved to Screening by a data fix (Option B)? Not done; none sit at Applied today.

- Q (applicant-profile-polish): the five scorecard criteria and the scorer list were assistant defaults (John did not answer). Confirm or change; should retail and head office use different criteria? Should an Offer require a scorecard? (now optional)
- Q: tests/employee_profile_http_test.php gives 54 PASS (floor 55) on the scratch copy, failing "A read-only note, no form or editable field in profile"; present before this patch. Needs its own look.
