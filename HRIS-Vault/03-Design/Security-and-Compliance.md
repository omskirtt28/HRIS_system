---
title: Security and Compliance
type: design
status: draft
tags: [design, security, compliance, ph-statutory, needs-verification]
updated: 2026-10-17
---

# Security and Compliance

## Purpose

An HRIS concentrates the most sensitive personal data a company holds: government IDs, salary, bank accounts, medical certificates, disciplinary records. This note defines how the system protects it and how it supports the company's obligations under the **Data Privacy Act of 2012 (RA 10173)**, its implementing rules, and National Privacy Commission (NPC) issuances.

> [!warning] NEEDS VERIFICATION
> This note describes obligations in general terms from working knowledge. It is not legal advice. Before go-live, confirm with the company's DPO or counsel: current NPC registration requirements for the employer, breach-notification timelines, and retention periods for each record class. No specific number of hours, days or years is asserted here as fact.

## Data classification

| Class | Examples | Handling |
|---|---|---|
| **Sensitive personal information** (RA 10173 term) | Government-issued IDs (SSS, TIN, PhilHealth, Pag-IBIG numbers), health data (medical certificates, maternity), marital status, disciplinary and legal proceedings | Field-level access permission, every read logged, never in notifications or logs, encrypted at rest where practical |
| **Personal information** | Name, address, contact, birth date, photo, schedule | Scope-filtered access, no public URLs |
| **Financial** | Compensation, payslips, bank account numbers, loans | Separate permission (`compensation.read`), account numbers encrypted at application level |
| **Operational** | Org structure, holiday calendar, job titles | Normal authenticated access |
| **Credentials** | Password hashes, session tokens, reset tokens | Never exposed through any API or log |

## Data Privacy Act mapping

| Principle / obligation | How the system supports it | Where |
|---|---|---|
| **Transparency** - data subject knows what is collected and why | Privacy notice shown at account activation; acknowledgement stored with version and timestamp | `privacy_acknowledgements` table, [[Self-Service-Portal]] |
| **Legitimate purpose** | Each data field tagged with purpose (payroll, statutory, HR admin); no field collected without a purpose | [[Data-Model]] column comments |
| **Proportionality** | Collect only what payroll/statutory/HR needs; optional fields stay optional | [[Employee-Records]] |
| **Access right** | Employee views their own 201 and payslips | SCR-740, SCR-711 |
| **Rectification right** | Change requests with approval trail | `employee_change_requests` |
| **Erasure/blocking** | Cannot erase payroll-relevant data while retention applies; support "restrict" flag that hides non-required data after retention | BR-224, retention job below |
| **Data portability / copy** | Employee can download their own data export (JSON/PDF) | Phase 2 |
| **Security measures** - organisational, physical, technical | See next sections | this note |
| **Breach management** | Incident runbook, detectable via audit log and auth events | below |
| **Accountability** | DPO contact shown in the privacy notice; audit log; processing register | Q19 in [[Open-Questions]] |

## Authentication and sessions

Detail in [[Auth-and-RBAC]]. Security-relevant minimums:

- Passwords: adaptive hash (Argon2id or bcrypt), per-user salt, min length enforced, check against common-password list. Never logged or emailed (BR-117).
- Lockout/backoff after repeated failures; rate limit login and reset endpoints per IP and per account.
- Session: server-tracked, absolute and idle timeout, rotate ID on login and privilege change, revocable (BR-118).
- MFA: **Should** for HR admin, payroll officer and sys admin roles (TOTP). Not MVP-blocking but design the `users` table with room for it.
- Password reset: single-use, short-lived token, response identical whether or not the email exists.

## Authorisation

- Deny by default: no declared permission means no route ([[Architecture]]).
- RBAC with scope; scope enforced in the repository layer (BR-113). IDOR is the top risk in this kind of system: every `{id}` in a path is re-checked against scope.
- Separation of duties (BR-114, BR-115, BR-119): editing employees vs running payroll; creating vs verifying a rate table; nobody approves their own item.
- Out-of-scope reads return 404 (BR-121 behaviour).

## Data protection

| Concern | Control |
|---|---|
| In transit | TLS everywhere once off-LAN; HSTS; no mixed content. Redirect HTTP |
| At rest, database | Disk-level encryption on the host; MySQL account with least privilege; app account has no `DROP`, and **no UPDATE/DELETE on `audit_log`** (BR-116) |
| At rest, fields | Application-level encryption for `employee_bank_accounts.account_no` and government ID numbers; key outside the repo and outside the DB; key rotation procedure documented |
| Documents | Stored outside web root; served via authorised endpoint only (BR-707); random storage names; original filename kept as metadata; virus scan hook (Could) |
| Backups | Encrypted, offsite, restore tested on a schedule (Q14). Backups contain PII and get the same access control as production |
| Secrets | Environment config, never in git; `.env` excluded; separate values per environment |
| Logs | Structured; must not contain passwords, tokens, government IDs, salary, or full request bodies for sensitive endpoints. Mask by field name allow-list |
| Test data | Never copy production PII to a dev machine. Use a seeder with fake Filipino names, or anonymise on export |
| Email | Notifications carry no sensitive values (BR-715); payslips never attached (BR-708) |
| Exports | Reports containing PII are generated as jobs, owner-only download, expire after a short window, and each download is logged |

## Application security checklist (per change)

1. Input validated server-side with an allow-list; output escaped by default in templates.
2. Parameterised queries only; no string-built SQL.
3. CSRF token on every unsafe request; `SameSite` cookies.
4. Mass assignment blocked by explicit field whitelist.
5. File uploads: size cap, extension and MIME allow-list, content sniffing, stored outside web root.
6. Security headers: CSP, `X-Content-Type-Options`, `Referrer-Policy`, `frame-ancestors none`.
7. Dependency updates and a lockfile; no unpinned packages.
8. Rate limiting on auth and export endpoints.
9. Error pages leak no stack traces or SQL in production.
10. Every state-changing action writes an audit row in the same transaction.

## Audit

Two separate logs, both append-only:

| Log | Records | Table |
|---|---|---|
| Authentication events | login success/failure, lockout, reset, session revoke, MFA | `auth_events` |
| Business audit | who, when, entity, entity_id, action, before/after JSON (sensitive fields masked as hashes), request_id, IP | `audit_log` |

**Read-audit** (not only writes) is required for: government IDs, compensation, bank accounts, medical documents, payslips viewed by someone other than the owner. This is what lets the company answer "who looked at this person's salary?" after a complaint. Depth and retention of row-level history is ADR-008, still open in [[Decision-Log]].

## Retention and disposal

Retention periods differ by record class (payroll records, DTR, 201 files, applicants who were not hired, medical documents). The Labor Code and BIR/SSS/PhilHealth/Pag-IBIG rules each set minimums.

> [!warning] NEEDS VERIFICATION
> Do not hard-code retention years. Model retention as a config table `retention_policies(record_class, retain_years, basis_reference, action)` and fill it only after the DPO/counsel confirms each class. See Q20 in [[Open-Questions]].

Mechanism: a scheduled job lists records past retention, produces a disposal proposal for human approval, then anonymises or deletes and writes an audit entry. Never auto-delete silently. Unsuccessful applicants' data (see [[Recruitment-and-Onboarding]]) gets the shortest policy and an explicit consent record for any talent-pool retention.

## Breach response

1. Detect: auth anomaly alerts, audit-log queries, user report.
2. Contain: revoke sessions, rotate secrets, disable affected accounts.
3. Assess: what data, which employees, what harm.
4. Notify: the NPC and affected data subjects within the legally required timeframe (verify the current rule with counsel).
5. Record: incident log with timeline and corrective actions.

The system's part: fast session revocation (`user_sessions`), tamper-evident audit log, and a query that lists exactly which employees' records a given user accessed in a time window.

## Data subject requests

A `data_requests` record (type: access, correction, blocking, erasure, complaint) with requester, due date, owner, outcome. HR handles them in the admin console; the DPO role gets read access to the audit log and the request queue, not to payroll amounts unless granted.

## Compliance calendar hooks

Statutory filings are in [[Reports-and-Analytics]]; labor standards in [[PH-Labor-and-Payroll-Notes]]. This note only covers privacy and security obligations.

## Open Questions

- [ ] Who is the DPO, and has the company registered its processing with the NPC? (Q19)
- [ ] Retention period per record class (Q20)
- [ ] Will HR use MFA from day one? Which method (TOTP app, email OTP)?
- [ ] Hosting location: local office server vs a PH/overseas VPS has cross-border transfer implications. (Q13)
- [ ] Is a Data Protection Impact Assessment needed before launch? Ask the DPO.

## Related

[[Auth-and-RBAC]] · [[Architecture]] · [[Data-Model]] · [[Business-Rules]] · [[Employee-Records]] · [[Self-Service-Portal]] · [[Notifications]] · [[Glossary]] · [[Decision-Log]]
