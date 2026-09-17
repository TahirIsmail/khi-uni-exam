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

| Rule                                                                                                                   | Enforced in                                                         |
| ---------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| No raw SQL fragments (`whereRaw`, `DB::statement`, ...) without a `// raw-sql-reviewed:` marker; values always bound   | `tests/Unit/ArchitectureTest.php`                                   |
| Controllers never use the `DB` facade or PDO                                                                           | `tests/Unit/ArchitectureTest.php`                                   |
| No weak hashing/randomness, `eval`, shell calls, `unserialize`, debug output                                           | `tests/Unit/ArchitectureTest.php` (Pest `php` + `security` presets) |
| `env()` only in config                                                                                                 | `tests/Unit/ArchitectureTest.php`                                   |
| No login, registration, password, profile, settings or admin screens; guests go to the kmu-cms login; unknown URLs 404 | `tests/Feature/Security/SecurityBaselineTest.php`                   |
| CSP with per-request nonce, frame/sniff/referrer/permissions headers, no caching of signed-in pages                    | `tests/Feature/Security/SecurityBaselineTest.php`                   |
| Single logout both ways (POST /logout, signed GET /sso/logout); deactivated users signed out mid-session               | `tests/Feature/Identity/LogoutTest.php`                             |
| Mass assignment, lazy loading and missing attributes fail outside production                                           | `AppServiceProvider` (strict models)                                |
| Permissions come from kmu-cms checkboxes (`Permissions::CATALOGUE` map); campuses, then CMS exam access limits         | `tests/Feature/Identity/AccessControlTest.php`                      |
| Audit log is append-only (DB triggers) and hash-chained; `audit:verify` runs daily; secrets are redacted               | `tests/Feature/Audit/AuditLogTest.php`                              |
| kmu-cms reads the audit log through `v_cms_audit_entries` with a SELECT-only account                                   | `tests/Feature/Audit/AuditLogTest.php`                              |
| MFA (when on in kmu-cms) for everyone, once per session, single-use codes, rate-limited                                | `tests/Feature/Identity/MfaTest.php`                                |
| Static analysis at PHPStan level 7                                                                                     | `composer types:check`                                              |

## Administration lives in kmu-cms (ADR-0004)

This app is only the question bank & exam module. Everything about who may use it is managed in
kmu-cms under **Question Bank & Exams** (sidebar) and **Roles → Assign Permission**:

| What                             | Where in kmu-cms                                                                  | Read here through                                 |
| -------------------------------- | --------------------------------------------------------------------------------- | ------------------------------------------------- |
| What a role may do               | Roles → Assign Permission → Question Bank & Exams (View/Add/Edit/Delete per item) | `v_cms_role_permissions` → `Permissions`          |
| Where a staff member may work    | Their campus (Staff) and Question Bank & Exams → Exam Access (optional limits)    | `v_cms_staff_branches`, `v_cms_staff_exam_scopes` |
| Two-factor authentication on/off | Question Bank & Exams → Exam Module Settings (Super Admin; off by default)        | `v_cms_exam_settings`                             |
| The audit log                    | Question Bank & Exams → Exam Audit Log (campus-wise)                              | kmu-cms reads `v_cms_audit_entries` here          |

- **Permissions.** Code checks catalogue codes (`Gate::allows('qbank.question.create')`), never role
  names. Each code is one CMS checkbox (`Permissions::CATALOGUE`); `php artisan cms:check-permissions`
  confirms they all exist. A CMS role marked Super Admin has every permission.
- **Campuses, then limits.** A Super Admin works in every active campus; other staff in their extra
  campuses, or else their own. Without Exam Access limits a user works everywhere in their campuses;
  with limits only in those programmes, professionals and courses (`AccessControl::allows()` with a
  `ScopeTarget`).
- **Rule for every later module.** Every question bank, exam, candidate and result table carries
  `branch_id`; every list filters by `branchIds()`; every change checks `allows(...)`.
- **Signing in and out.** Only through kmu-cms (ADR-0002). There is no login, profile or settings
  screen here. Log out in either app logs out of both: `POST /logout` here sends the browser to the
  CMS logout, which comes back through a signed 60-second token on `GET /sso/logout`. The sidebar has
  "Back to CMS".
- **Two-factor authentication.** When turned on in kmu-cms, everyone must set up an authenticator
  (`/mfa/setup`, 8 recovery codes shown once) and pass a challenge once per session. Codes are
  single-use (`SingleUseTotpProvider`), 5 attempts a minute per user; every pass, failure and setup is
  audited without the code. Lost phone: `php artisan user:mfa-reset email --reason=...`.
- **Audit log.** `AuditLogger::record()` inside the same transaction as the change, with the campus
  (`branch_id`). Rows cannot be updated or deleted (MySQL triggers), each stores the previous row's
  hash, and `php artisan audit:verify` checks the chain nightly. kmu-cms shows it through a
  SELECT-only account on `v_cms_audit_entries` (`php artisan cms:audit-reader-sql`).
- **Browser tests** (kmu-cms repo, local): `node tests/sso/exam_admin_e2e.mjs https://kmu-cms.test https://kmu-assess.test`
  and `tests/sso/sso_e2e.mjs`.

## Decisions

- [ADR-0002 — Staff sign in once, in kmu-cms (SSO)](adr-0002-sso-from-cms.md)
- [ADR-0004 — Administration of this module lives in kmu-cms](adr-0004-administration-in-cms.md)
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
| 7    | Permissions, campuses and limits, audit log, MFA — administered from kmu-cms (ADR-0004)                   | both       | Done                            |
| 8    | Question bank tables + immutability triggers                                                              | kmu_assess |                                 |
| 9    | Question editor, preview, validation                                                                      | kmu-assess |                                 |
| 10   | Search, versions, diff, timeline                                                                          | kmu-assess |                                 |
| 11   | Excel/CSV import                                                                                          | kmu-assess |                                 |
| 12   | Review, pre-hoc, approval                                                                                 | kmu-assess |                                 |
| 13   | Acceptance testing of the increment                                                                       | both       |                                 |

Exam delivery (including ADR-0003) is built in the later delivery phase, but its tables and
identity rules are designed now so nothing built earlier has to change.
