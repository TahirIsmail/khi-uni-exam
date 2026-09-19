# Acceptance of the first increment

The blueprint sets fourteen criteria for this increment (academic structure, question bank, import,
review and pre-hoc). This page maps each one to the test that proves it and records the result of
the run on **20 September 2026**, after the changes that follow KMU's own description of the QBank. Everything here is repeatable: the commands are in the table.

| Suite                                                       | Result     |
| ----------------------------------------------------------- | ---------- |
| kmu-assess `php artisan test`                               | 210 passed |
| kmu-assess `php artisan test tests/Scale` (50,000 versions) | 1 passed   |
| kmu-assess `node tests/browser/question_editor.mjs`         | 35 passed  |
| kmu-assess `node tests/browser/question_import.mjs`         | 24 passed  |
| kmu-assess `node tests/browser/question_review.mjs`         | 36 passed  |
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

- One check of `courses_screens.mjs` fails against a copy of the current development database
  because that database already contains a discipline with the code `ANA` (left behind while the
  screens were being tried by hand). On a copy without that row the file passes 35 of 35. It is
  data, not code: the same file's Course ID checks pass either way.
- `tests/Scale` is not part of a normal test run. It writes 50,000 questions into the testing
  database outside a transaction — MySQL only fills a FULLTEXT index for committed rows — and
  clears them again, whatever happens.
