# Plan: Remove portal account
Spec: SPEC-remove-portal-account.md (approved 2026-11-02). Touches a migration, so STOP for John's go-ahead before T1.

## Order
1. T1 migration `users.removed_at` (additive, down guarded while any row has it) -> everything else depends on it.
2. T2 `FoundationRepository::removeUser` + `restoreUser`; `users()` filters `removed_at IS NULL`.
3. T3 POST actions `remove_user` / `restore_user` in index.php (CSRF, HR gate).
4. T4 UI: kebab "Remove account" (confirm), Removed accounts view with Restore.
5. T5 sweep: every query joining `users` hides removed; Generate account ignores tombstoned email.
6. T6 tests, vault sync, check.php.

## Risks
- Hard FKs on users: mitigated by soft removal (never DELETE).
- Tombstoned email must not collide with the UNIQUE email index; restore fails cleanly if the email was reused.
- HR scope: HR may remove HR-portal and employee-portal accounts, never Admin/Super Admin (confirm if John wants otherwise).
- Last-Super-Admin guard.

## Test scenarios
1. Remove account: login fails, sessions revoked, employee row unchanged except user_id/company_email NULL, Generate account then yields the corrected email.
2. Account with audit/ticket history removes with no FK error.
3. HR removes HR account OK; HR removing Admin/self/last Super Admin refused; non-HR 403.
