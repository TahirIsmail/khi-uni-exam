# The examination phase — how an exam is built, sat and marked

The question bank is done. This page is the plan for everything that happens to a question after it
is stored: it is chosen for a paper, the paper is sat, the answers are marked, the results are
published, and what the statistics say goes back to the question. It covers KMU's own steps 5 to 12.

Four things were settled with the university before any of it was built:

| Question                   | Settled on                                                                                       |
| -------------------------- | ------------------------------------------------------------------------------------------------ |
| Where the exam runs        | **Inside this module**, as a computer-based examination — engine, timer, autosave and proctoring |
| What a paper covers        | **One Course ID** — one module or subject per paper                                              |
| Where candidates come from | **A list imported for each examination** (the CMS student register is not filled yet)            |
| How the pass mark is set   | **A fixed percentage** written on the examination                                                |

Each of these can be widened later without changing what is built first: a paper's items already
carry their own Course ID, and the pass mark lives on the examination rather than in code.

## Where each part is used from

Everything is administered in kmu-cms and done in this module, as
[ADR-0004](adr-0004-administration-in-cms.md) says. Under the CMS's **Exams** menu:

| Menu item                  | Opens                | Who sees it                                         |
| -------------------------- | -------------------- | --------------------------------------------------- |
| Open Question Bank & Exams | the question bank    | `qbank_questions`                                   |
| **Create Exam**            | `/exams`             | `exam_blueprints` or `exam_papers`                  |
| Conduct Exam               | `/exams/conduct`     | `exam_candidates`, `exam_checkin` or `exam_monitor` |
| Results & Marks            | `/results`           | `exam_results` or `exam_item_analysis`              |
| Exam Access                | the CMS's own screen | `exam_access`                                       |
| Exam Audit Log             | the CMS's own screen | `exam_audit_log`                                    |
| Exam Module Settings       | the CMS's own screen | Super Admin                                         |

**Create Exam** is in the menu now. Conduct Exam and Results & Marks are added in the step that builds
what they open (17 and 20–21), so nobody is ever shown a button with nothing behind it.

Each link carries a single-use SSO ticket, so nobody signs in twice
([ADR-0002](adr-0002-sso-from-cms.md)). Every permission already exists in the CMS: the
`exam_*` and `proctor_*` categories of the "Question Bank & Exams" group were created in step 7.

## The journey

```
Blueprint ──► Paper ──► Moderate ──► Lock ──► Sit ──► Mark ──► Results ──► Analyse
   (TOS)     (QBank)   (committee)  (frozen)  (CBT)  (auto +   (approve   (item, KR-20,
                                                      rubric)  + publish)  back to QBank)
```

The last arrow matters: what the statistics say about a question comes back to the bank as one of
the same five decisions the reviewers use — Accept, Retain in QBank, Review, Revise, Remove /
Discard.

### 1. Blueprint (Table of Specification)

Written before any question is chosen, so the paper is built to a plan rather than the plan written
around whatever was picked. Each row says: discipline / subject, topic, question type, how many
questions and the marks for each. Above the rows sit the overall targets — the cognitive mix
(Recall, Understanding, Application, Analysis) and the difficulty mix (Easy, Moderate, Difficult).

The screen keeps a running total of planned questions and planned marks against the examination's
declared total, and the blueprint cannot be approved until they agree. Approving it needs
`exam_blueprints_approve`.

### 2. The examination

What it is and when it is sat: programme, year or semester, examination type, Course ID, academic
session, date, start time, duration, total marks, pass percentage, negative marking, and the
optional sections (for example Section A multiple choice, Section B short answer) with their own
marks and time.

The title writes itself from the chain — "MBBS First Professional Annual Examination 2026 —
Foundation Module" — and can be changed.

### 3. The paper

"Fill from the QBank" chooses questions for each blueprint row: only questions the approving
authority stored (Accept or Retain in QBank), of that Course ID and examination type, weighted to
the difficulty mix and preferring questions not used recently. Every chosen item shows its
reference, topic, type, cognitive and difficulty level, how many times it has been used and when it
was last used, so the setter can see what they are being given.

The setter then swaps, locks, adds or removes items by hand. The screen warns about:

- two items with the same content hash (the same question twice),
- two items that give each other away (same topic, overlapping answer),
- an item used within the period the university sets,
- an item whose author is the person setting the paper.

A coverage panel compares the blueprint with what is actually in the paper, row by row. The paper
also carries how it is presented: whether the question order and the option order are fixed or
shuffled for each candidate.

### 4. Moderation, finalising and locking

The examination committee works through the whole paper, not one question at a time: coverage
against the blueprint, duplication, cueing, and the answer key. Comments are kept with the paper.

A paper can only be finalised when the blueprint is satisfied, every item has a key, the marks add
up to the declared total and no comment is unresolved. Finalising freezes the paper version the way
a submitted question version is frozen — by database trigger, so a direct `UPDATE` is refused too —
seals the answer key, records a content hash and writes an audit row.

Publishing a paper is a separate permission from finalising it, and the paper is not readable by
anyone until its release time. A change after finalising means a new paper version, never an edit.

### 5. Sitting the exam

The engine is described in
[ADR-0003](adr-0003-exam-resume-on-another-computer.md): the server holds the attempt, answers are
saved within about a second, and a candidate whose computer dies continues on another one with the
same paper, the same order, their answers and the right amount of time left.

### 6. Marking, results and analysis

Objective types are marked by the server against the sealed key. Short answers and essays are marked
against the question's rubric, by two examiners where the university asks for it, with a third
opinion when they disagree beyond a threshold.

Results are raw scores until the pass percentage is applied, the controller approves them and they
are published — three separate permissions. Re-keying a question after the exam (because an item was
found faulty) is a recorded action with the before and after, and every affected result is rescored.

The analysis is KMU's heading 9: overall statistics, item analysis (difficulty and discrimination
index), distractor analysis, KR-20 and Cronbach's alpha, compliance with the table of
specifications, and each question's usage history. It writes to `qb_question_usage` and
`qb_posthoc_decisions`, which already exist.

## What step 14 built: the examination and its blueprint

**The examination** (`exm_examinations`) is one sitting of one Course ID. It is set up by choosing, in
KMU's order, the programme, the year or semester, the examination (Annual, Supplementary, Regular or
Retake — only those the programme holds) and the Course ID. The programme, year and term are then read
from the course, so they can never disagree with it. Besides that: a title (written from the choices
until somebody writes their own), an optional academic session, the date and start time, the duration,
the total marks, the pass percentage, whether a wrong answer loses marks and how much, and
instructions to candidates. Its reference is `EX-2026-0001`, handed out one at a time and never reused.

Times are typed and shown in the examination time zone (`EXAM_TIMEZONE`, Asia/Karachi by default) and
stored in UTC, so the server's clock — the only one that matters while an exam is sat — is never in
doubt.

**The blueprint** (`exm_blueprints`, `exm_blueprint_rows`, `exm_blueprint_targets`, `exm_sections`)
is written on one screen: optional sections, then rows — a topic (or a whole heading, whose subtopics
count too), a type of question, how many, and what each is worth — and the two overall mixes. Beside
the rows the screen shows, live, how many marks are planned against the total, and for every row how
many questions the bank holds for it (in use, not archived, filed under this examination and topic). A
shortage is a warning, not a bar: the questions can still be written before the paper is built.

**Approval** follows the same rule as the question bank. A blueprint is a draft, is submitted by the
person who wrote it once its rows add up to the total marks and its mixes to 100%, and is approved —
or sent back with a reason — by somebody else who holds the approving right; nobody approves what
they wrote or submitted. Approving records a fingerprint (SHA-256) of the blueprint, which the paper
is later checked against. An approved blueprint can be reopened, with a reason, until a paper is
finalised; every step is in the audit log.

**What is frozen, and where:** a submitted or approved blueprint's rows, mixes and sections, and the
examination's course, examination type and total marks, are refused by the database itself, so a
direct `UPDATE` fails too. Blueprints follow draft → submitted → approved (→ draft again) and no
other order, and nothing past the blueprint stage is ever deleted.

**Who may do what** (all CMS checkboxes that already existed): see and list — `exam_papers` View or
`exam_blueprints` View; set an examination up — `exam_papers` Add; write and submit a blueprint —
`exam_blueprints` Edit; approve, send back or reopen — `exam_blueprints_approve`. Every action is also
limited to the campus being worked in and to the courses the person's exam access allows.

## Security while an exam is being sat

| Layer                  | What it does                                                                                                                                  |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| The paper              | Immutable once finalised; unreadable before its release time; finalising and publishing are different rights, so two people are involved      |
| The candidate          | Candidate number and a one-time exam PIN issued at check-in; photo shown to the invigilator; one attempt each                                 |
| One computer at a time | Heartbeat sessions. A second sign-in resumes only if the first computer has been silent; otherwise it is blocked and the invigilator decides  |
| The browser            | Safe Exam Browser key or lockdown mode, full screen enforced, copy, paste, right-click, print and developer tools blocked, tab changes logged |
| Delivery               | Questions served under short-lived signed tokens; **the answer key never reaches the browser**; the clock is the server's only                |
| Order                  | Question and option order differ per candidate, so neighbours cannot read across                                                              |
| Answers                | Append-only events with sequence numbers: idempotent, replay refused, every change attributable                                               |
| Proctoring             | Events with a severity, evidence kept for the period the university sets, and a committee decision recorded against the case                  |
| Afterwards             | No result is visible before it is approved and published; re-keying and rescoring are recorded with before and after values                   |

All of it sits under the campus rules, the CMS permissions and the hash-chained audit log that the
first increment already built.

## Steps

Each step ends with its tests, screenshots and the university's approval before the next one starts.

| Step | Work                                                                   | Where      | Status  |
| ---- | ---------------------------------------------------------------------- | ---------- | ------- |
| 14   | The three menu items and their deep links; blueprint tables and screen | both       | Planned |
| 15   | The examination, and building a paper from the QBank                   | kmu-assess | Planned |
| 16   | Moderation, finalising, locking and paper versions                     | kmu-assess | Planned |
| 17   | Candidates, centres, rooms, allocation, check-in and PINs              | kmu-assess | Planned |
| 18   | The delivery engine, including resuming on another computer (ADR-0003) | kmu-assess | Planned |
| 19   | Browser lockdown and proctoring events                                 | kmu-assess | Planned |
| 20   | Marking: automatic, and rubric-based with two examiners                | kmu-assess | Planned |
| 21   | Results: pass mark, approval and publication                           | kmu-assess | Planned |
| 22   | Post-hoc analysis, and the decision going back to the question bank    | kmu-assess | Planned |
