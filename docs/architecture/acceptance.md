# Acceptance of the first increment

The blueprint sets fourteen criteria for this increment (academic structure, question bank, import,
review and pre-hoc). This page maps each one to the test that proves it and records the result of
the run on **20 September 2026**, after the changes that follow KMU's own description of the QBank — its seven statuses, its five
decisions, the pre-hoc assessment in full, and Create → Review → Approve → Stored in QBank on every
question's page. Everything here is repeatable: the commands are in the table.

| Suite                                                       | Result     |
| ----------------------------------------------------------- | ---------- |
| kmu-assess `php artisan test`                               | 216 passed |
| kmu-assess `php artisan test tests/Scale` (50,000 versions) | 1 passed   |
| kmu-assess `node tests/browser/question_editor.mjs`         | 35 passed  |
| kmu-assess `node tests/browser/question_import.mjs`         | 24 passed  |
| kmu-assess `node tests/browser/question_review.mjs`         | 37 passed  |
| kmu-cms `node tests/academic/*.mjs` (5 files)               | 120 passed |
| kmu-cms `bash tests/security/http_smoke.sh`                 | 43 passed  |
| kmu-cms `node tests/security/browser_csrf_guard.mjs`        | 8 passed   |
| kmu-cms `node tests/sso/sso_e2e.mjs`                        | 12 passed  |
| kmu-cms `node tests/sso/exam_admin_e2e.mjs`                 | 49 passed  |
| kmu-assess PHPStan (level 7), Pint, ESLint, vue-tsc         | clean      |

## The criteria

| #   | Criterion                                                                                                                                       | Proved by                                                                                                                                                                                                                                            | Result      |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------- |
| 1   | MBBS (modular), BDS and DPT (semester) structures are configured through the UI without code changes, including a new level added to a template | kmu-cms `tests/academic/structure_screens.mjs` (23 checks: all three programmes, calendar types, level templates, a level added and removed) and `legacy_forms.mjs`                                                                                  | Met         |
| 2   | A duplicate Course ID (any case, including a retired one) is rejected; renaming a course keeps its ID and history shows the old name            | kmu-cms `tests/academic/courses_screens.mjs` (35 checks, including "Course ID check: taken (any case)", "Course ID cannot change once curriculum items exist", and the history rows)                                                                 | Met         |
| 3   | An author creates one valid item of each type; invalid items cannot be submitted and every error names the field                                | `tests/Feature/Acceptance/IncrementOneTest.php` — one question written and sent for review in each of the 12 types; an unfinished one saves as a draft but is refused at submission, naming `stem` and `options`                                     | Met         |
| 4   | A submitted version cannot be changed through the UI, the API, or a direct SQL `UPDATE` on content columns                                      | Same file: the editor redirects to the read-only view, the update endpoint refuses it, and the database trigger refuses the `UPDATE`                                                                                                                 | Met         |
| 5   | Editing an active question creates v2; v1 stays active until v2 is approved, then becomes superseded; the diff shows the changes                | Same file, and `tests/browser/question_review.mjs` for the same journey through the screens                                                                                                                                                          | Met         |
| 6   | An author cannot review or approve their own item (UI hidden **and** API 403)                                                                   | Same file — the author holds both rights and still gets `can.review: false`, `can.approve: false`, an empty approvals queue, and 403 from both endpoints                                                                                             | Met         |
| 7   | Pre-hoc values from each reviewer and the consolidated values are all stored and visible in the timeline                                        | Same file — four rows (author proposal, two reviewers, consolidated) and all four visible on the question's timeline                                                                                                                                 | Met         |
| 8   | Workflow status, pre-hoc decision and (placeholder) post-hoc decision are separate fields in separate tables                                    | Same file — `qb_question_versions.status`, `qb_prehoc_assessments`, `qb_posthoc_decisions` (created in this step as a placeholder; its screens belong to the post-hoc phase)                                                                         | Met         |
| 9   | Importing a 500-row file with 20 invalid rows commits 480, the report lists exactly those 20 with reasons, and exact duplicates are caught      | Same file (group `slow`) — 480 committed, 20 reported by line with their reason, and re-uploading the same file warns on all 480 as already in the bank                                                                                              | Met         |
| 10  | Search combining course + topic + Bloom + difficulty + status + author returns correct results in < 1 s on 50,000 versions                      | `tests/Scale/SearchScaleTest.php` — filters 13 ms, filters with words 92 ms, status counts 180 ms                                                                                                                                                    | Met         |
| 11  | A user scoped to DPT cannot see, search, export or open MBBS items by URL or API                                                                | `tests/Feature/Acceptance/IncrementOneTest.php` — the search shows only their programme, the MBBS question is 403 by every address, and the export contains only their own questions                                                                 | Met         |
| 12  | Every action produces an audit row with actor, IP and before/after values; chain verification passes and a manually altered row is detected     | Same file, plus `tests/Feature/Audit/AuditLogTest.php` — rows cannot be updated or deleted at all, and a row written straight into the table breaks the chain, which names its id                                                                    | Met         |
| 13  | CSRF protection is enabled in kmu-cms and existing CMS workflows pass the regression checklist                                                  | kmu-cms `tests/security/browser_csrf_guard.mjs` (forms, `form.submit()`, XHR, jQuery, fetch, multipart, cross-origin) and `http_smoke.sh` (43 checks), with the academic and SSO suites as the workflow regression                                   | Met         |
| 14  | MFA is enforced for Super Admin, QBank Administrator and Approver roles                                                                         | `tests/Feature/Acceptance/IncrementOneTest.php` and `tests/Feature/Identity/MfaTest.php` — **wider than asked**: when a Super Admin turns it on in kmu-cms it applies to every member of staff, which the university chose over per-role enforcement | Met (wider) |

## The university's own requirement list, point by point

KMU's "QBANK/LMS CATEGORIES STRUCTURE" has nine headings. This is where each one is, checked again
after the changes that put KMU's words on the screens.

| #   | KMU's heading                                                                         | Where it is                                                                                                                                                                                              | State                  |
| --- | ------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------- |
| 1   | Academic Program — MBBS, BDS, DPT                                                     | kmu-cms → Academic → **2. Add Programmes**; all three are in the database with their calendar type (MBBS/BDS annual, DPT semester)                                                                       | Done                   |
| 2   | Professional / Year                                                                   | kmu-cms → **3. Programme Structure**; "MBBS First Professional", "DPT First Professional, Semester I". The editor asks for it between the programme and the course                                       | Done                   |
| 3   | Examination type for MBBS & BDS — Annual, Supplementary                               | kmu-cms → **6. Exam Types**, offered only to annual programmes; every question carries one                                                                                                               | Done                   |
| 4   | Examination type for semester programmes — Regular, Retake                            | Same screen, offered only to semester programmes; a question of an annual programme cannot take one                                                                                                      | Done                   |
| 5   | Modular curriculum — MBBS                                                             | kmu-cms → **5. Courses** → Curriculum: Foundation Module → Anatomy / Physiology / Biochemistry / Pathology → topic (e.g. Upper Limb). Eight MBBS modules with their disciplines and 78 topics are seeded | Done                   |
| 6   | Course / subject categories for BDS and DPT                                           | The same screen; for BDS and DPT the first level is the subject's topic, because their curriculum has no module heading. Seven BDS and eight DPT Course IDs with their topics are seeded                 | Done                   |
| 7   | Course IDs                                                                            | kmu-cms → **5. Courses (Course IDs)**: the ID is unique, cannot be reused, cannot change once questions exist, and every rename is kept in the course's history                                          | Done                   |
| 8a  | Cognitive level — Recall, Understanding, Application, Analysis                        | The four levels, in the editor ("3. Pre-hoc assessment"), in every review, on approval, in the search and in the export                                                                                  | Done                   |
| 8b  | Difficulty level — Easy, Moderate, Difficult                                          | The three levels, in the same places                                                                                                                                                                     | Done                   |
| 8c  | Question quality / decision — Accept, Review, Revise, Remove/Discard, Retain in QBank | The five decisions, offered to every reviewer and to the approving authority, each with its own outcome; the one taken is kept on the version and is what its status reads                               | Done                   |
| 9.1 | Overall examination statistics                                                        | Computed from examination results                                                                                                                                                                        | Post-hoc phase         |
| 9.2 | Item analysis of each question                                                        | `qb_question_usage` holds the difficulty index, discrimination and candidates per use; `qb_posthoc_decisions` holds the decision, in KMU's same five words                                               | Tables ready           |
| 9.3 | Distractor analysis                                                                   | `qb_question_usage.option_shares` holds the option-wise shares each time a question is used                                                                                                              | Tables ready           |
| 9.4 | Reliability — KR-20, Cronbach's alpha                                                 | Computed from a whole examination's results                                                                                                                                                              | Post-hoc phase         |
| 9.5 | TOS compliance                                                                        | Needs the blueprint and the paper, which the delivery phase builds                                                                                                                                       | Delivery phase         |
| 9.6 | Question history — times used, examination/date, candidates                           | `qb_question_usage`, and the "Use in examinations" section of the question's page; the search already filters on used / unused and on the date last used                                                 | Screen ready, unfilled |

Nothing in headings 1–8 is missing. Heading 9 is a report about examinations that have been sat, so
it is written once the delivery phase produces results; the per-question tables it reads are already
in place, with KMU's own wording for the decision.

## Where this increment went further than the criteria

- **Two-factor authentication covers everybody, and is a setting** (criterion 14). It is off by
  default and a Super Admin turns it on in kmu-cms; when on, every member of staff must use an
  authenticator app, not only the three named roles.
- **Campus (branch) isolation** runs through everything: questions, pictures, tags, imports,
  reviews, exports and the audit log all belong to a campus, and the CMS decides which campuses a
  member of staff may work in.
- **Administration lives in kmu-cms** ([ADR-0004](adr-0004-administration-in-cms.md)): roles,
  permissions, exam access, review settings and the audit log are managed there, so the module has
  no administration screens of its own.
- **The import and the export share their columns**, so a file can be exported, edited in Excel and
  brought back in.

## What is deliberately not in this increment

- **Exam building, delivery and marking** (blueprint phases 5 onwards): blueprints, papers,
  candidates, the exam engine, proctoring, results and reports. The identity, campus and audit
  rules they need are already in place, and [ADR-0003](adr-0003-exam-resume-on-another-computer.md)
  records how a candidate's exam will survive a crash.
- **Post-hoc analysis and its report.** The university's report asks for the examination
  statistics (mean, median, standard deviation, pass rate), item analysis, distractor analysis,
  reliability (KR-20, Cronbach's alpha), compliance with the table of specifications, and each
  question's usage history. All of that is computed from examination results, which the delivery
  phase produces. The tables that hold the per-question part — `qb_posthoc_decisions` and
  `qb_question_usage` — exist so the shape is right from the start, but nothing writes to them yet.
- **Notifying an author by email** when their question is reviewed. The reviewer's queue, the
  approver's queue and the dashboard show what is waiting; email belongs with the delivery phase,
  which is when the system starts writing to candidates as well.
- **kmu-cms branding** (step 3b) is waiting for the KMU logo and letterhead.

## Notes from the run

- The kmu-cms academic files run against `kmu_cms_e2e`, a copy of the CMS database that
  `tests/academic/reset_e2e_db.sh` makes fresh. On this run all five files passed (35, 27, 24, 11
  and 23 checks). Run against a copy that already has a discipline with the code `ANA` — left behind
  while the screens were being tried by hand — one check of `courses_screens.mjs` fails on that row.
  It is data, not code.
- **The questions written by "Tmp …" in the development database come from these test runs.** Each
  browser file creates its own temporary members of staff in kmu-cms (Tmp Author, Tmp Importer,
  Tmp TMP-RV-…), signs them in through SSO, writes a question and walks it through the whole
  journey. Afterwards it retires the test course, deactivates the people and marks the questions
  **Remove / Discard** — it cannot delete them, because the database refuses to delete a question, a
  status log entry or a submitted review, by design. That is also what "Remove / Discard" means
  everywhere in the module: the question is out of use and out of the searches, but it is kept with
  the reason, so it is always possible to see why it was never used. To start a demonstration from
  an empty bank, rebuild the module's database (`php artisan migrate:fresh`) rather than deleting
  rows; kmu-cms is untouched by it.
- `tests/Scale` is not part of a normal test run. It writes 50,000 questions into the testing
  database outside a transaction — MySQL only fills a FULLTEXT index for committed rows — and
  clears them again, whatever happens.
