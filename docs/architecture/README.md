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
| Search filters are validated and never reach beyond the campus and exam access                                         | `tests/Feature/QuestionBank/SearchAndHistoryTest.php`               |
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

- **Where it belongs, in the CMS's own order.** The editor asks for the **programme** (MBBS, BDS,
  DPT), then the **Course ID** of that programme, then the **topic** inside the course's curriculum —
  shown with its parents, for example `Cardiology (discipline) → Acute coronary syndrome`. Which
  curriculum levels take questions is set per programme in the CMS (Programme Structure: MBBS is
  discipline → topic → subtopic with questions on topic and subtopic; BDS and DPT are topic →
  subtopic). Only courses in the user's campus and exam access are offered, and only programmes they
  have a course in. The chosen topic decides the professional, term and discipline stored with the
  version.
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
- **Pictures** are uploaded with a description (needed for screen readers and printed papers), kept
  off the public web root and served only to staff of the same campus (`/questions/media/{id}`), and
  the same file in a campus is stored once. Which version uses which picture is recorded from the
  image addresses in its text (`qb_version_media`).
- **Tags and discipline.** Authors add tags for their campus as they write; the discipline defaults to
  the topic's own and can be set per question, because blueprints count by discipline.
- **Duplicates and search.** Each version stores a content hash of the normalised stem and options,
  and a plain-text copy for searching.
- **Versions.** Editing a question that is past drafting starts the next version as a draft copied
  from the one in use (`POST /questions/{question}/versions`); the version in use stays active until
  the new one is approved. References come from a counter (`Q-2026-000123`).
- **The way in.** The sidebar shows "Question bank" to anyone who may open it, and the dashboard
  shows the campus's counts by status. With no course yet, the editor says which CMS screens to use
  (Academics → Course, then Curriculum).
- Browser test (local): `node tests/browser/question_editor.mjs https://kmu-assess.test` (24 checks,
  including the picture upload; it reuses one test course and author in the CMS).

## Finding and comparing questions (step 10)

- **Search** (`/questions`): words are matched against each version's plain-text copy with MySQL's
  FULLTEXT index and with a plain "contains" search, so part of a word or a reference
  (`Q-2026-000123`) still finds the question; best matches come first.
- **Filters**: programme, Course ID, topic (including everything under it), discipline, type, level
  of thinking, expected difficulty, tag, author, marks range, date changed, status, written by me,
  archived, and "same text twice". Every value is validated — an unknown status, type or sort order
  is refused rather than ignored — and the search never leaves the campus and exam access.
- **Question page** (`/questions/{question}`): every version with its status, author and dates; a
  timeline of what happened (written, sent for review, changes asked for, approved, put in use,
  superseded, retired) from the append-only status log; and other questions in the campus with the
  same text.
- **Comparing two versions** (`/questions/{question}/diff?from=&to=`): the question text word by word
  (removals struck through, additions underlined), options matched by their letter with any answer-key
  change called out, and a table of what else changed (type, marks, course, topic, counts). The word
  diff is a small longest-common-subsequence walk (`TextDiff`), with no dependency.

## Importing questions from a spreadsheet (step 11)

- **Two steps, never one**: an upload is only _checked_ (`qbank.import.run`) — the file is read, every
  row validated and shown back with its line number — and a separate _commit_
  (`qbank.import.commit`) writes the good rows into the bank as drafts. Nothing reaches the question
  bank until someone commits, and a committed file is kept as the record of what was imported.
- **Files**: CSV, TSV and Excel (`.xlsx`, `.xls` via `phpoffice/phpspreadsheet`), up to 10 MB and
  2,000 rows. The file is stored on the private local disk under the campus, and deleted when the
  import is discarded.
- **Columns** are matched loosely, so "Question", "Stem" and "Question text" all mean the same thing
  (`SpreadsheetReader::ALIASES`). A course, topic or type can also be chosen once for the whole file;
  a row that names its own always wins. What the columns hold:
  `options` = `ECG | Chest radiograph | …`, `correct` = `A` or `A,C` (or `true`/`false`),
  `items` = `statement = true` (multiple true/false) or `prompt -> B` (matching/ordering),
  `answers` = accepted wordings separated by `|`, or a number with a tolerance (`7.40 ± 0.05`).
  A ready-made template with an example row per common type is at `/questions/imports/template`.
- **Every row is checked as if it were typed into the editor**: the same `QuestionValidator`, the same
  per-type rules, the same HTML sanitiser, plus the campus and exam-access check. A row for a course
  outside the user's exam access, or in another campus, is refused.
- **Repeats**: the same question twice in one file is refused (the second row names the line it
  repeats); one already in the bank is only a warning, because a question may legitimately be
  rewritten. Both are found by the `content_hash` the editor already uses.
- **Partial imports are normal**: a file with bad rows can still be committed — the good rows go in,
  the rest are corrected in the spreadsheet and uploaded again. Each created draft records
  `source = 'import'` and the row it came from, and each row records the question and version it
  became, so the two can always be traced to each other.
- **In the audit log**: `qbank.import.checked`, `qbank.import.committed`, `qbank.import.discarded`,
  and a `qbank.question.created` entry per draft.

### Taking questions out

- **Export** (`/questions/export`): the search results as a CSV, answer keys and all. It is its own
  permission (Export Questions & Answer Keys in kmu-cms) because the file leaves the system; only
  what the search itself would show is in it, at most 5,000 questions, and every export is written
  to the audit log (`qbank.question.exported`). Its columns are the ones the import reads, so a file
  can be exported, edited in Excel and brought back in.

## Review, pre-hoc assessment and approval (step 12)

Three things are kept apart, each with its own history and its own authorised role (blueprint 9):
the **workflow status** says where a version is in the process, a **review** is one reviewer's
outcome, and a **pre-hoc assessment** is the expert judgement about the question itself.

- **Assignment**: sending a question for review assigns reviewers automatically — round-robin by who
  has the fewest open reviews, from the staff of the campus who may review that course. The author
  is never assigned their own question. Somebody with "Assign Reviewers" can take a review back
  (with a reason) and ask somebody else. The due date comes from the kmu-cms setting.
- **The reviewer's outcome** is either _request changes_ (a comment is required; the question goes
  straight back to its author and the other open reviews are called off) or _a review_: the
  item-writing checklist, a pre-hoc decision, and — for whoever may record it — the level of
  thinking, the expected difficulty and the expected pass rate. A submitted review can never be
  changed or deleted (database triggers), so a second opinion means a second review.
- **The checklist** (`qb_review_checklist_items`) is the NBME item-writing guide: cover-the-options,
  no negative lead-in, homogeneous options, no absolute terms, no grammatical cues, the key is not
  the longest, plus two advisory items. Which ones apply depends on the kind of question, and adding
  a rule is a new row. The required ones must pass before a question can be approved.
- **The approval gate**: enough reviews for this round, no required checklist item marked as failed,
  an "accept" decision, an approver who is not the author, and — when the reviewers decided
  differently — a reason. A review from before the author last changed the question does not count:
  each submission is its own round.
- **Approval** stores the consolidated pre-hoc row (the one row per version the database enforces)
  and copies its level of thinking and difficulty onto the version. A blueprint later draws on those
  values, never on the author's proposal, which is kept as its own row. Approved questions go into
  use straight away, or wait, depending on the kmu-cms setting; putting one into use supersedes the
  version that was in use before.
- **Turning a question down** archives the version with a reason and, when nothing usable is left,
  archives the question too. Nothing is deleted.
- **Screens**: _My reviews_ (`/reviews`), the review workspace
  (`/questions/{question}/versions/{version}/review` — the question as a candidate sees it, the
  checklist, the pre-hoc form, the comments, and the approver's decision), and _Approvals_
  (`/approvals`, split into ready to decide, still in review, and approved but not in use). The
  dashboard shows how many reviews are waiting for you.
- **Administered in kmu-cms** (Question Bank & Exams → Exam Module Settings, Super Admin only): how
  many reviews a question needs, how many days a reviewer has, whether approval puts the question
  into use straight away, and whether authors see reviewer names. Every change is logged there in
  words.
- **In the audit log**: `qbank.review.assigned`, `qbank.review.cancelled`, `qbank.review.submitted`,
  `qbank.review.changes_requested`, `qbank.prehoc.recorded`, `qbank.question.approved`,
  `qbank.question.activated`, `qbank.question.rejected`.
- **The question's timeline** shows all of it in one place: who wrote it, what each reviewer said
  and judged, what the approver settled on, and every status change — with reviewer names hidden
  from the author when kmu-cms asks for that.
- **The lists themselves are the university's**: the level of thinking is Recall, Understanding,
  Application and Analysis (Evaluation and Synthesis are rows that are switched off, in case a
  department wants the full Bloom scale later); the difficulty is Easy, Moderate, Difficult; and the
  decision is Accept, Review, Revise or Remove / Discard. "Retain in QBank" is a decision taken
  after an examination, so it belongs to the post-hoc list.
- **Post-hoc decisions** (what a department decides _after_ an examination) have their own tables,
  `qb_posthoc_decision_types` and `qb_posthoc_decisions`, and where a question has been used has
  `qb_question_usage` — one row per version per examination, with the candidates, the difficulty and
  discrimination indices and the share of candidates per option. They are placeholders: the three
  judgements — workflow status, pre-hoc, post-hoc — are separate records from the start, and the
  screens that fill them belong to the later delivery and post-hoc phases.

## Acceptance

- [Acceptance of the first increment](acceptance.md) — the blueprint's fourteen criteria, the test
  that proves each one, and the result of the run.

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
| 10   | Search and filters, version history, timeline, side-by-side diff                                          | kmu-assess | Done                            |
| 11   | Excel/CSV import                                                                                          | kmu-assess | Done                            |
| 12   | Review, pre-hoc, approval                                                                                 | both       | Done                            |
| 13   | Acceptance testing of the increment                                                                       | both       | Done                            |

Exam delivery (including ADR-0003) is built in the later delivery phase, but its tables and
identity rules are designed now so nothing built earlier has to change.
