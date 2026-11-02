# Plan: rec-manpower-intake

Full plan (Taglish, with risks and execute prompt): `HRIS-Vault/05-Implementation/Plans/2026-11-02-rec-manpower-intake.md`. Spec: `SPEC-rec-manpower-intake.md`.

## Dependency order
T1 (migration 022) -> T2 (service) -> T3 (routes) -> T4 (UI). T5 (demo clients, data migration) is independent of T2-T4 but runs after T1 so one verified dump covers both. T6 sweep and T7 checks last.

## Slices
- Vertical slice 1: T1 + T2 + T3 + service/HTTP tests (HR can approve or decline through a POST, no UI polish).
- Slice 2: T4 (list, filters, dialogs, Add employer).
- Slice 3: T5 (remove demo clients on scratch, then live after verified dump) and T6/T7.

## Checkpoints
- After slice 1: `php tests/rec_manpower_intake_test.php` and the http test pass on the scratch DB.
- After slice 2: syntax check plus HTTP render test; no browser unless John says go.
- After slice 3: `check.php full` OK, vault synced, John reviews; commit decision is John's (the bulk-accounts and remove-account patches are still uncommitted in shared files).

## Risks
See the vault plan. Main one: the design deviation from spec v2 (row created at Approve, not at intake) needs John's OK; fallback is a nullable `client_id`.
