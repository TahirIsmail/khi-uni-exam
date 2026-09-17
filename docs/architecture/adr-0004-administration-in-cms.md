# ADR-0004 — Administration of this module lives in kmu-cms

Status: accepted (2026-09-17)

## Context

Step 7 first built role/permission, staff scope and audit log screens, a password login, profile
settings and break-glass accounts inside KMU Assessment. KMU staff already manage roles, staff and
settings in kmu-cms and sign in there. Two places to manage access would confuse administrators and
could disagree.

## Decision

KMU Assessment contains only the question bank & exam functionality. In kmu-cms:

- **Roles → Assign Permission** has a "Question Bank & Exams" group (migration 0006). Each permission
  code here maps to one checkbox there (`Permissions::CATALOGUE`).
- **Question Bank & Exams → Exam Access** sets optional limits (programmes, professionals, courses)
  per staff member. No limits = everywhere in their campuses.
- **Question Bank & Exams → Exam Module Settings** (Super Admin) turns two-factor authentication on
  or off for the module. Off by default.
- **Question Bank & Exams → Exam Audit Log** shows this app's audit log, campus-wise, read through a
  SELECT-only MySQL account on `v_cms_audit_entries`.

KMU Assessment reads the CMS data through the read-only `v_cms_*` views (ADR-0002). It has no login,
profile, settings or administration screens; guests are sent to the CMS login; logging out in
either app logs out of both (signed 60-second logout token); the sidebar has "Back to CMS".

## Consequences

- One place for administrators; both apps apply the same campus rule.
- The audit log is still written and protected here (append-only, hash chain); the CMS only reads it.
- Break-glass local accounts are gone: if kmu-cms is down, nobody can sign in to the module. Exam
  delivery for candidates (a later step) must not depend on the CMS being up during an exam.
- Adding a permission means adding a category/checkbox in a kmu-cms migration and the mapping here;
  `php artisan cms:check-permissions` fails until both exist.
- A CMS session that simply expires (no logout) does not end the module session; that ends after the
  module's own idle timeout (SESSION_LIFETIME, 30 minutes).
