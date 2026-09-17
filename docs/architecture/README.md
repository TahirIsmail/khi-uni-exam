# KMU Assessment — Architecture

This app holds the question bank, examinations, exam delivery, results and post-hoc analysis
for KMU. The full design (approved Sep 2026) is the "KMU QBank & Examination Management System —
Architecture Blueprint" document; this folder records the decisions the code must follow.

## Two apps, one MySQL server

| App                            | Database     | Owns                                                                                                                                                                                   |
| ------------------------------ | ------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| kmu-cms (CodeIgniter 3)        | `kmu-cms`    | Students, admissions, staff, fees, timetable, and the **entire academic structure** (programmes, semesters, intakes, professionals, courses / Course IDs, curriculum tree, exam types) |
| kmu-assess (this app, Laravel) | `kmu_assess` | Question bank, blueprints, exams, candidates' exam attempts, delivery, proctoring, results, analytics, audit                                                                           |

- Academic structure exists **once**, in kmu-cms. This app only reads it (read-only views and a SELECT-only DB user).
- This app never writes to the kmu-cms database.
- Rows here reference kmu-cms IDs (`cms_staff_id`, `student_id`, `course_id`), never copies.

## Code layout

```
app/
  Domain/<Module>/Actions     business operations (one class per use case, runs in a DB transaction, writes the audit log)
  Domain/<Module>/Models      Eloquent models for that module
  Domain/<Module>/Policies    authorisation (permission + scope checks)
  Domain/<Module>/Enums       statuses and fixed value sets
  Http/Controllers/<Module>   thin: authorise, validate (FormRequest), call an Action, return a response
  Http/Requests/<Module>      all input validation
  Http/Middleware             cross-cutting request rules (security headers, active account, ...)
  Models/User.php             staff identity (framework default location)
resources/js/pages/<module>   staff screens (Vue 3 + Inertia)
resources/js/exam-client      candidate exam app (separate Vue SPA; added in the delivery step)
routes/web.php                staff routes (all behind auth)
routes/sso.php                SSO entry from kmu-cms (POST /sso/cms)
routes/delivery.php           candidate exam API (added in the delivery step)
docs/architecture             decisions (ADRs)
```

Modules: Identity, Academic (read-only), QuestionBank, Blueprint, Exam, Candidate, Centre,
Delivery, Proctoring, Result, Analytics, Audit.

## Security rules (enforced by tests)

| Rule                                                                                                                 | Enforced in                                                         |
| -------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| No raw SQL fragments (`whereRaw`, `DB::statement`, ...) without a `// raw-sql-reviewed:` marker; values always bound | `tests/Unit/ArchitectureTest.php`                                   |
| Controllers never use the `DB` facade or PDO                                                                         | `tests/Unit/ArchitectureTest.php`                                   |
| No weak hashing/randomness, `eval`, shell calls, `unserialize`, debug output                                         | `tests/Unit/ArchitectureTest.php` (Pest `php` + `security` presets) |
| `env()` only in config                                                                                               | `tests/Unit/ArchitectureTest.php`                                   |
| No public registration, no self-deletion, no public landing page; unknown URLs 404                                   | `tests/Feature/Security/SecurityBaselineTest.php`                   |
| CSP with per-request nonce, frame/sniff/referrer/permissions headers, no caching of signed-in pages                  | `tests/Feature/Security/SecurityBaselineTest.php`                   |
| Login rate-limited; inactive or SSO-only accounts cannot use password login                                          | `tests/Feature/Security/*`                                          |
| Mass assignment, lazy loading and missing attributes fail outside production                                         | `AppServiceProvider` (strict models)                                |
| Permissions: codes live in `Permissions::CATALOGUE`; CMS roles get grants here; branches, then scopes, limit where | `tests/Feature/Identity/AccessControlTest.php`                      |
| Audit log is append-only (DB triggers) and hash-chained; `audit:verify` runs daily; secrets are redacted              | `tests/Feature/Audit/AuditLogTest.php`                              |
| Static analysis at PHPStan level 7                                                                                   | `composer types:check`                                              |

## Access control and audit

- **Who may do what.** Roles are managed in kmu-cms (Settings → Roles) and read through `v_cms_staff_roles`.
  kmu-assess grants catalogue permissions to those roles (`sec_role_permissions`), so there is one list
  of roles for both apps. A CMS role marked Super Admin has every permission.
- **Which branch (campus).** Branches are the outer limit, with the kmu-cms rule: a Super Admin (or
  break-glass account) works in every active branch; other staff in their extra branches
  (`staff_accessible_branches`), or else their own branch. `AccessControl::branchIds()` gives the list.
- **Where inside the branch.** `sec_user_scopes` limits a user to everywhere in their branches (`all`)
  or to programmes, professionals or courses. A user without a scope can still see screens but can
  act on nothing that has a place.
- **Rule for every later module.** Every question bank, exam, candidate and result table carries
  `branch_id`; every list and search filters by `branchIds()`; every change checks
  `allows($user, $permission, ScopeTarget::...)`, which refuses another branch's data.
- **Break-glass.** `php artisan user:break-glass email --reason=...` gives a local account every
  permission for a CMS outage; granting and revoking are audited.
- **Audit log.** `AuditLogger::record()` inside the same transaction as the change. Rows can't be
  updated or deleted (MySQL triggers), each row stores the previous row's hash, and
  `php artisan audit:verify` (daily, 02:30) reports the first broken row.
- After changing the catalogue in code, run `php artisan permissions:sync` (migrations also seed it).

## Decisions

- [ADR-0002 — Staff sign in once, in kmu-cms (SSO)](adr-0002-sso-from-cms.md)
- [ADR-0003 — A candidate's exam survives a crash or network loss and resumes on another computer](adr-0003-exam-resume-on-another-computer.md)

## Implementation steps (first increment)

| Step | Work                                                                                                      | Where      | Status                          |
| ---- | --------------------------------------------------------------------------------------------------------- | ---------- | ------------------------------- |
| 1    | App skeleton, security baseline, architecture rules, identity foundation (CMS staff link)                 | kmu-assess | Done                            |
| 2    | CMS security: CSRF, HttpOnly cookies, SHA-1 password path, close open URLs (`/migrate`, test controllers) | kmu-cms    | Done                            |
| 3    | Replace ICE seed data with KMU values                                                                     | kmu-cms    | Done (3b branding pending logo) |
| 4    | Academic tables: fix existing, add `acad_*`                                                               | kmu-cms DB | Done                            |
| 5    | Academic screens + delete guard                                                                           | kmu-cms    | Done                            |
| 6    | SSO from kmu-cms (ADR-0002) + read-only DB user and views                                                 | both       | Done                            |
| 7    | Permissions, scopes, audit log, MFA                                                                       | kmu-assess | 7a done; 7b screens, 7c MFA     |
| 8    | Question bank tables + immutability triggers                                                              | kmu_assess |                                 |
| 9    | Question editor, preview, validation                                                                      | kmu-assess |                                 |
| 10   | Search, versions, diff, timeline                                                                          | kmu-assess |                                 |
| 11   | Excel/CSV import                                                                                          | kmu-assess |                                 |
| 12   | Review, pre-hoc, approval                                                                                 | kmu-assess |                                 |
| 13   | Acceptance testing of the increment                                                                       | both       |                                 |

Exam delivery (including ADR-0003) is built in the later delivery phase, but its tables and
identity rules are designed now so nothing built earlier has to change.
