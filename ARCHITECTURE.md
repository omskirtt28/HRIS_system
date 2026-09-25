# HRIS Architecture Baseline

**Status:** Baseline v1  
**Initial implemented module:** Recruitment  
**Source reviewed:** `Recruitment/index.html` and `pmbsi-hrsd-handover.zip`

## 1. Purpose

This document is the permanent architecture reference for the HRIS. New features must fit this structure before implementation. The goal is to prevent duplicated logic, mixed responsibilities, inconsistent data models, and module-to-module coupling.

## 2. Current Source Assessment

The supplied project contains two different assets:

1. **Recruitment / RWMS prototype** — a self-contained HTML/JavaScript preview with sample data. It already defines the intended recruitment experience and workflow, but it does not have a real recruitment database or API.
2. **PMBSI HR Service Desk backend (reference source)** — Node.js/Express/PostgreSQL with authentication, RBAC, scope control, audit, workflows, notifications, employee/client/branch masters, reports, and migration tooling. It is not a recruitment backend.

**Decision:** Recruitment becomes an HRIS business module. Reusable HR Service Desk capabilities become shared HRIS Core services where applicable. Ticket-specific logic must not be copied into Recruitment.

## 3. Architectural Style

Use a **modular monolith** first.

Reasons:
- one deployable application is simpler to maintain while HRIS modules are still growing;
- modules remain logically isolated through folders, services, APIs, permissions, and database ownership;
- shared authentication, audit, notifications, documents, and organization masters stay centralized;
- modules can later be extracted into services only if scale or organizational needs justify it.

```text
Browser / Mobile Web / PWA
          |
          v
+-----------------------------+
|        HRIS Web/API         |
|-----------------------------|
| HRIS Core                   |
| - Auth & Sessions           |
| - RBAC & Scope              |
| - Users                     |
| - Organization Masters      |
| - Employee Master           |
| - Documents                 |
| - Notifications             |
| - Audit & Security Events   |
| - Workflow / Approvals      |
|-----------------------------|
| Business Modules            |
| - Recruitment               |
| - Employee Records          |
| - Time & Attendance         |
| - Leave                     |
| - Payroll Integration       |
| - Performance               |
| - Employee Relations        |
| - Offboarding               |
+-----------------------------+
          |
          v
      MySQL 8.x
```

## 4. Technology Baseline

The approved implementation stack for this HRIS is:

- **Runtime:** PHP 8.2+
- **Backend:** PHP modular monolith
- **Database:** MySQL 8.0+
- **Database access:** PDO with native prepared statements
- **Auth:** secure server-side PHP sessions with RBAC and scope enforcement
- **Password hashing:** PHP `password_hash()` / `password_verify()`
- **Frontend:** responsive web UI; current Recruitment HTML is a design reference, not production architecture
- **File storage:** storage abstraction; production object storage preferred
- **Email/SMS:** provider abstraction; never hardcode provider logic in Recruitment

This PHP + MySQL stack is the active implementation baseline. A future stack change must be an explicit architecture decision before implementation.

## 5. HRIS Core Boundaries

### 5.1 Identity & Access
Owns:
- users;
- credentials;
- sessions;
- roles;
- permissions;
- scopes;
- account status;
- MFA/security policies.

No business module may implement its own login, role table, or session logic.

### 5.2 Organization Master
Owns:
- company/legal entities;
- clients;
- branches/sites;
- departments;
- positions;
- cost centers when needed.

Recruitment references these records by ID. It must not maintain duplicate client, branch, department, or position lists.

### 5.3 Employee Master
Owns active and historical employee identity after hiring/conversion.

**Applicant is not an employee.** Recruitment owns applicants and applications until an approved hiring/deployment conversion occurs.

### 5.4 Workflow & Approvals
Shared workflow capability may be used for configurable approvals such as manpower request approval or exceptional offer approval. Recruitment state transitions remain controlled by Recruitment domain rules.

### 5.5 Notifications
All module notifications go through one notification service. Recruitment emits events such as:
- application received;
- interview scheduled/rescheduled;
- candidate endorsed;
- client decision received;
- offer sent/accepted/declined;
- deployment ready/completed.

### 5.6 Documents
A single document service manages metadata, versions, storage references, visibility, and audit. Recruitment attaches resumes, IDs, interview files, offer documents, and pre-employment requirements through this service.

### 5.7 Audit
Every sensitive mutation must be audited. Audit records are append-only.

## 6. Recruitment Module Scope

The first HRIS module covers:

```text
Manpower Request
      |
      v
Job Opening / Publication
      |
      v
Applicant + Application
      |
      v
Applied -> Screening -> Interview -> Endorsed
      -> Client Review -> Offer -> Deployment -> Deployed
      |
      +-> Rejected / Withdrawn / On Hold where applicable
```

### Recruitment subdomains

1. Manpower Requests
2. Job Openings / Careers Site
3. Applicant Master
4. Applications
5. Screening
6. Interviews
7. Endorsements
8. Client Review / Approval
9. Offers
10. Pre-deployment Requirements
11. Deployment
12. Recruitment Reports
13. Recruitment Configuration

## 7. Portal Model

The supplied prototype defines four views. Preserve the concept but use one security model.

### Applicant Portal
- browse jobs;
- apply;
- upload permitted documents;
- track own application;
- withdraw application where allowed.

### Recruitment / HR Portal
- dashboard;
- applicants;
- pipeline;
- manpower requests;
- interviews;
- endorsements;
- offers/deployment;
- reports;
- recruitment settings according to permission.

### Client Portal
- see only candidates formally endorsed to that client;
- review candidate profiles using field minimization;
- approve, decline, request interview, or return for clarification;
- see only the client’s own requisitions and recruitment reports.

### System Admin
- users;
- roles and permissions;
- organization masters;
- recruitment configuration;
- integrations;
- audit;
- system monitoring/security.

Admin privileges must not automatically grant business actions unless permissions explicitly do so.

## 8. Recruitment State Model

Canonical stage codes are stable identifiers; display labels may be configured.

```text
APPLIED
  -> SCREENING
  -> INTERVIEW
  -> ENDORSED
  -> CLIENT_REVIEW
  -> OFFER
  -> DEPLOYMENT
  -> DEPLOYED
```

Side outcomes:

```text
REJECTED
WITHDRAWN
ON_HOLD
RETURNED
```

Rules:
- every stage change creates immutable stage history;
- current stage is stored on the application for fast querying;
- invalid jumps are rejected server-side;
- client decisions are separate records, not only stage text;
- accepted offer is required before normal deployment;
- employee creation happens through an explicit conversion step, not merely by changing a stage.

## 9. Request Flow

```text
Frontend
  -> Route/Controller
  -> Authentication
  -> Permission check
  -> Scope check
  -> Request validation
  -> Recruitment service
  -> Transaction / SQL
  -> Audit + domain event
  -> Notification worker
  -> Response
```

Frontend visibility is not security. Every access rule must be enforced on the server.

## 10. Module Folder Contract

Recommended structure:

```text
src/
  core/
    auth/
    rbac/
    scope/
    audit/
    notifications/
    documents/
    organization/
    employees/
    workflow/
  modules/
    recruitment/
      recruitment.routes.js
      recruitment.permissions.js
      recruitment.validators.js
      recruitment.events.js
      services/
        manpowerRequests.service.js
        jobs.service.js
        applicants.service.js
        applications.service.js
        interviews.service.js
        endorsements.service.js
        offers.service.js
        deployments.service.js
        recruitmentReports.service.js
      queries/
      policies/
  db/
    migrations/
  web/
    modules/
      recruitment/
```

Do not put Recruitment-specific code under generic `utils/` or generic ticket services.

## 11. Module Integration Rules

Recruitment may depend on HRIS Core. HRIS Core must not depend on Recruitment.

Allowed:

```text
Recruitment -> Users/RBAC
Recruitment -> Clients/Branches/Departments/Positions
Recruitment -> Documents
Recruitment -> Notifications
Recruitment -> Audit
Recruitment -> Workflow
Recruitment -> Employee Conversion API
```

Not allowed:

```text
Employee Core -> Recruitment UI state
Auth -> Recruitment tables
Notifications -> Recruitment database internals
Recruitment -> Ticket tables
Recruitment -> hardcoded client/branch lists
```

## 12. Transaction Boundaries

Use database transactions for operations that must succeed together, including:
- application submission + initial stage history;
- stage transition + stage history + activity event;
- endorsement + candidate snapshot + stage transition;
- offer acceptance + status update;
- deployment completion + employee conversion marker;
- role/scope/security changes.

Notification delivery occurs after the business transaction is committed.

## 13. Event Names

Use stable domain events:

```text
recruitment.application.created
recruitment.application.stage_changed
recruitment.interview.scheduled
recruitment.interview.completed
recruitment.endorsement.sent
recruitment.client_review.decided
recruitment.offer.sent
recruitment.offer.accepted
recruitment.offer.declined
recruitment.deployment.ready
recruitment.deployment.completed
recruitment.employee_conversion.completed
```

## 14. Future HRIS Module Rule

Every new HRIS module must define before coding:
- scope;
- owned tables;
- roles/permissions;
- state transitions;
- events;
- integrations;
- reports;
- audit requirements;
- links to other modules.

No new module may bypass this baseline.


## Locked Next Development Phase

After the Recruitment module, the next HRIS development phase is **Employee Information Management / 201 File + Employee Master + Organization Structure**. This foundation must be implemented before Attendance/Timekeeping, Leave, Payroll, Performance, Employee Relations, and Offboarding so all downstream modules reference one canonical employee and organization model.
