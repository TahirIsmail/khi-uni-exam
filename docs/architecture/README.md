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
| Question versions are frozen once approved; workflow steps, one active version, append-only log                        | `tests/Feature/QuestionBank/SchemaRulesTest.php`                    |
| Question text is sanitised before storing; type rules and the campus/exam access are enforced server-side              | `tests/Feature/QuestionBank/QuestionWritingTest.php`                |
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

## Question bank (step 8)

A question is a stable identity (`qb_questions`, one campus and course, archived but never deleted)
with a chain of versions (`qb_question_versions`). The content and the workflow status live on the
version, and only one version can be `active` at a time (a generated unique key enforces it).

- **Kinds of question** (`qb_question_types`, seeded): single best answer, multiple response,
  true/false, multiple true/false, extended matching (EMQ), matching pairs, put-in-order, short
  answer, numerical, fill in the blanks (cloze), long answer/essay with a rubric, label the image.
  Each row says what the type is made of (shared options, sub-parts, typed answers, manual marking)
  and its settings with defaults (shuffle, partial credit, word limits, tolerance, ...), so the
  editor and the validator read the type instead of hard-coding it.
- **One set of tables serves every type**: `qb_question_options` (A, B, C ...), `qb_question_items`
  (statements, lead-ins, prompts, blanks, steps), `qb_question_answers` (accepted text or a number
  with a tolerance), `qb_question_rubric_criteria`, `qb_references`, `qb_media` + `qb_version_media`,
  `qb_tags` + `qb_version_tags`, and the append-only `qb_version_status_log`.
- **Immutability, in the database** (migration `…_add_question_bank_immutability`): a version and
  everything belonging to it can be written to only while `draft` or `changes_requested`. Afterwards
  its content is frozen (a change means a new version), status moves follow the workflow of
  blueprint 8.2 (`VersionStatus` mirrors it, and a test proves code and database agree), only a draft
  version can be deleted, questions are archived, and the status log cannot be changed.
- **Campus.** Every row carries `branch_id`: the campus the author is working in (step 8a).
- `course_id`, `node_id` and the other academic ids point into kmu-cms. MySQL cannot enforce foreign
  keys across databases here, so the application validates them against the read-only `v_cms_*`
  views and the two databases stay independently restorable.

## Writing questions (step 9)

The editor is one screen per question, driven by the type: `/questions` lists the campus's questions,
`/questions/create` and `…/versions/{version}/edit` write a draft, and `…/versions/{version}` shows a
question as a candidate would see it, with the answer key.

- **Where it belongs.** Course and topic come from kmu-cms; only courses in the user's campus and exam
  access are offered, and only topics of a level that takes questions. The topic decides the
  programme, professional, term and discipline stored with the version.
- **The type decides the shape.** Options, sub-parts, accepted answers, rubric and settings appear
  from the type row (`qb_question_types`), so a new type needs no new screen.
- **Rules are applied on the server** (`QuestionValidator`): stem length, marks, option and answer
  counts per type, one key where the type allows one, duplicate options, matching answers that exist,
  numbers with a tolerance, rubric lines and references. The editor shows the same list live
  (`POST /questions/check`), and submission runs it again on what is stored — a draft can always be
  saved, but it cannot be sent for review while an error stands.
- **Advice, not walls.** The item-writing checklist (all/none of the above, negative lead-in, longest
  option is the key, absolute terms, missing explanation or reference) appears as warnings.
- **Text is cleaned before it is stored** (`QuestionHtml`, Symfony HTML Sanitizer): a small tag
  allow-list, http(s) or relative links only; scripts, event handlers, styles and frames are dropped.
  The preview shows the stored text, so what is checked is what a candidate sees.
- **Duplicates and search.** Each version stores a content hash of the normalised stem and options,
  and a plain-text copy for searching.
- **Versions.** Editing a question that is past drafting starts the next version as a draft copied
  from the one in use (`POST /questions/{question}/versions`); the version in use stays active until
  the new one is approved. References come from a counter (`Q-2026-000123`).
- Browser test (local): `node tests/browser/question_editor.mjs https://kmu-assess.test`.

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
| 8    | Campus context; question bank tables, all question types, immutability triggers                           | both       | Done                            |
| 9    | Question editor, live validation, candidate preview, versions                                             | kmu-assess | Done                            |
| 10   | Search, versions, diff, timeline                                                                          | kmu-assess |                                 |
| 11   | Excel/CSV import                                                                                          | kmu-assess |                                 |
| 12   | Review, pre-hoc, approval                                                                                 | kmu-assess |                                 |
| 13   | Acceptance testing of the increment                                                                       | both       |                                 |

Exam delivery (including ADR-0003) is built in the later delivery phase, but its tables and
identity rules are designed now so nothing built earlier has to change.
