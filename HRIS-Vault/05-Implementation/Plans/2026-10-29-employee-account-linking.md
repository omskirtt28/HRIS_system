# Employee account linking and PMBSI position plan - 2026-10-29

Request: show eligible active unlinked accounts including HR accounts, make linking clear in Employees and Users, and support Human Resource PMBSI assignment. No DB writes, browser, deployment or commit.

Evidence: Employee picker restricts portal to employee; Users linking enforces managedUser (only Super Admin manages HR/admin accounts). Recruitment shares the picker but its server permits only employee portal. PMBSI position helper accepts exact HR aliases, excluding a PMBSI suffix and Human Resources Assistant. Preserve portal access rules and avoid granting account management to employee-only managers.

Plan: targeted changes in app/EmployeeRepository.php and index.php: separate employee and recruitment picker scope; enforce active/unlinked account and existing managed-user restrictions server-side, preserve unchanged links; show role/email and clear status; improve Users selection to distinguish choosing from unlinking. Extend shared position helper for Human Resource PMBSI and ensure UI and save validation share it. Sweep same-class callers; list larger policy issues. No schema or role changes. Preserve pre-existing edits.

Checks: static review of (1) eligible/unavailable and HR account permission, (2) existing link and Users unlink/relink, (3) PMBSI/2LOG filtering and save consistency. php -l on changed PHP; no runtime, DB or browser testing.
