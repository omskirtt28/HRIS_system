---
title: Dashboard cleanup and template-based Incident Report
type: plan
status: approved
tags: [plan, incidents, dashboard, documents]
updated: 2026-10-27
---

# Dashboard cleanup and template-based Incident Report

## Approved intent

Implement John's supplied plan in the existing PHP ticket engine and design system. Remove the entire dashboard "Needs your action today" strip; preserve the greeting, navigation, filters, six KPIs and lower panels. Three setup warnings link to the matching expanded Ticket Viewers section, filtering employee rows before pagination. Keep pending/incident destinations with consistent hover and keyboard focus.

Pipeline remains a progress indicator. Eligible handling HR gets Update status beside it, revealing the existing status form with required remarks, allowed transitions and stale-version protection. HR reporter or any participant (including CC) remains excluded from handling, with a short explanation. Period picker and Download Report are explicitly deferred to another patch.

## Official report

Exact supplied master located at `C:\Users\John Joshua\Downloads\PMBSI Incident Report (IR) - Template.docx`; immutable SHA-256 `ed809eb1ea579c94efb9a8ea05dda070163f5ca3da393aaf57ee074f95a313ae`. Preserve original ZIP parts, logo, header/footer, tables, margins, labels, fonts and checkbox layout; replace only verified field XML in a generated copy. No global sample-name replacement.

Form: Employee Information (filer, not another employee), Incident Details, Incident Description, Initial Action Taken, Customer Involved, Supporting Documents. Use verified employee fields; missing immediate head/contact are report-only answers. Report date is server submission date; incident date/start and optional end time are separate. Free-text involved/witness names confer no system access; existing participant/CC picker stays separate. Prepared by is filer and actual position with no signature; preserve printed Branch Immediate Head caption. Reviewed by name/position blank, printed caption retained.

Eight nature choices map to existing categories: Attendance Violation -> Attendance-related; Conduct & Behavior/Dishonesty/Negligence/Insubordination -> Workplace conduct; Damage/Loss -> Property or equipment; Others -> Other. Follow-up amendment by John (2026-10-27): nature specification is optional, including Others, no minimum and max5000 instead of200. Supporting Others specification also max5000 but remains required if selected. Historical categories remain readable/filterable and are not rewritten.

Freeze submitted answers/master hash/version in the existing ticket payload. Required DOCX generation participates in create transaction and existing moved-file cleanup/submission-key deduplication; failure rolls back and keeps form answers. PDF conversion failure alone preserves report/DOCX and shows fallback + eligible HR retry. Redirect successful filing to the case.

Reporter and eligible handling HR alone can preview PDF/download DOCX. CC/other participants keep existing conversation access. Historical reports show no generated official document, without backfilling. Generic attachments keep their existing types/limits.

Case order: pipeline -> official document -> conversation/composer; HR controls/people/summary/attachments/timeline stay in right rail. Document before conversation on mobile.

## Ownership and contracts

- Backend: TicketViewerModule/Service focus whitelist and pre-pagination filter; IncidentDocument helper; TicketIncident validation/snapshot; TicketService transactional generation; IncidentModule routes/actions/failed answers/case redirect; config.local.example converter settings; unchanged master in resources/templates.
- UX: HrDashboardViews, TicketViewerViews, IncidentViews, IncidentCaseViews, hr-ops.css, tickets.css. Backend contract before dependent form/preview edits.
- Main: coordination, plan and affected Obsidian/spec/changelog updates. Preserve unrelated workspace changes.
- Viewer focus: `branches_without_sc`, `retail_missing`, `ho_missing`; anchors `tv-branches-without-sc` / `tv-missing`.
- Authenticated GET `incident-document&id=...&format=pdf|docx`: renew case/document gate every request, private filenames/storage outside served roots, no-store. Validate a configured absolute storage root against the project/document roots, including canonical/symlink paths; do not rely solely on Apache `.htaccess` because the documented PHP built-in server ignores it.
- CSRF-protected POST `incident-act`, `core_incident_pdf_retry`: eligible handler only, stale-version protection.
- Self-hosted LibreOffice with explicit configured executable, isolated temporary profile and bounded conversion; no external document upload. No database migration or new table.

## Sweep and verification

Check all viewer report callers/default behavior, query/anchor/pagination preservation; dashboard link states; status markup versus POST eligibility; all Incident create entry points; snapshot validation and serialization; private downloads/retries; exact XML mappings/checkbox reset; duplicate submit and rollback cleanup; historical categories. Fix small same-class defects only. Do not expand schema, grants, payments or delete scope.

Three static scenarios only:
1. Strip removed, retained dashboard intact, warning destinations filtered before pagination and expanded.
2. Status UI/server consistency, remarks/transitions/version and HR-participant exclusion; document privacy separate from conversation.
3. Master field/checkbox mapping, frozen snapshot, duplicate submission, rollback cleanup, DOCX-required/PDF-optional fallback and retry.

Run changed PHP `php -l`, changed JS `node --check` where applicable; review diff/CRLF, then HRIS patch tester and read-only simplify gatekeeper (proposals only, max two fix rounds). No browser/app/DB tests/writes, commit, push or deployment. Converter operation and rendered DOCX/PDF fidelity remain unverified pending separately authorized acceptance.

Related: [[Employee-Tickets]], [[Screens]], [[API-Surface]], [[Business-Rules]], [[Design-System]], [[Codex-Agent-Workflow]], [[Session-Log]], [[Progress]].

## Static QA checkpoint

Follow-up submit blocker: planner -> backend IncidentDocument (optional nature_other/max5000; supporting_other max5000 without relaxing its conditional requirement) -> UX IncidentViews (matching labels/help/maxlength). Source scenarios: blank/short Others accepted by field validation;201-5000 allowed; supporting Others still conditionally required. php -l passed both changed files; no runtime tests or further reviews per John's instruction. This supersedes the earlier required nature specification decision only.

First QA: 12/13 PHP lint passed, dashboard missing PHP closing tag failed; all three source-extracted inline scripts passed node syntax. Master SHA-256 and all 160 paragraph/checkbox anchors matched; changed text files CRLF-only, scoped diff whitespace clean. Round 1 fixes restored dashboard transition, moved new IR storage outside served roots with canonical validation, and preserved PDO handling for the own-employee lookup. Default new storage is `C:/xampp/hris-private/incident_documents`, configurable via `incident_documents.storage_root`; optional PDF requires configured `libreoffice_path`. Actual local config, directories and generated documents were not created/modified by testing. Existing COE/generic attachment storage follows the older inside-webroot pattern and is listed for a separate authorized patch, not changed here.

John explicitly stopped further review/recheck and will check correctness himself (2026-10-27). Active tester interrupted; simplify skipped. Source implementation complete; final fixes not lint-reverified and runtime/browser/DB/converter/rendered fidelity remain unverified. No commit/push/deployment. This task-specific instruction overrides the remaining review pipeline without changing the standing default for other tasks.
