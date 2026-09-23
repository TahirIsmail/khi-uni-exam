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
| **Marking**                 | `/marking`           | `exam_marking_assign`, `exam_marking` or `exam_marking_adjudicate` |
| **Results & Marks**        | `/results`           | `exam_results`, `exam_results_approve`, `exam_results_publish` or `exam_results_rescore` |
| Exam Access                | the CMS's own screen | `exam_access`                                       |
| Exam Audit Log             | the CMS's own screen | `exam_audit_log`                                    |
| Exam Module Settings       | the CMS's own screen | Super Admin                                         |

**Create Exam, Conduct Exam, Marking and Results & Marks are in the menu now.** Item analysis
(`exam_item_analysis`), step 22's own screen, is not gated into Results & Marks — it will get its
own place once it exists, the same rule that kept every one of these out of the menu until it did.

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

**A blueprint has to be one a paper could be built to.** Unless the institution turns it off
(`EXAM_REQUIRE_QUESTIONS_IN_BANK=false`, when a short bank is only a warning), a blueprint cannot be
submitted — and is checked again when it is approved — while the question bank holds fewer questions
than it asks for. A heading counts everything under it, so what is asked of a topic is what its own
rows and the rows below it ask: the topics form a tree, and a paper can be built exactly when no topic
is asked for more than it holds.

**Finding the approval.** People who may approve blueprints see **Exam approvals** in the module's
menu, with a count of what waits for them (never what they wrote or submitted themselves), and a note
at the top of the examinations list. The person waiting is shown, by name, who could approve it — read
from kmu-cms, so colleagues who have not yet opened the module are named too — or, when nobody else
holds the right, how to give it to someone. Approving approves the _plan_ — topics, numbers, marks;
the questions themselves are chosen next, in the paper, and the committee reads them before the paper
is locked (step 16). A blueprint that can no longer be changed is shown as plain text, not as
greyed-out fields.

**Who may do what** (all CMS checkboxes that already existed): see and list — `exam_papers` View or
`exam_blueprints` View; set an examination up — `exam_papers` Add; write and submit a blueprint —
`exam_blueprints` Edit; approve, send back or reopen — `exam_blueprints_approve`. Every action is also
limited to the campus being worked in and to the courses the person's exam access allows.

## What step 15 built: the paper

**Starting it.** Once a blueprint is approved the paper can be started (`exm_papers`; `exam_papers`
Edit is the right to choose questions). It is built to the approved blueprint and to nothing else: if
the blueprint is reopened the paper holds still, and it carries on when the blueprint is approved
again, with a note if the blueprint changed in the meantime.

**What may go in.** One place says which questions a row can draw on (`CandidatePool`): questions in
use in the bank (active, not archived), of this campus and course, filed under this examination's
type, of the row's type, and from the row's topic or anything below it. The automatic draw, the
picker and the checks on every change all use it, so they cannot disagree. The paper item pins the
_version_ that was chosen: whatever happens to the question afterwards, the paper keeps asking what
was chosen, and says so if a newer version has since come into use.

**Filling it from the bank.** "Fill the gaps" keeps everything that is there and draws what is
missing; "Draw again" first takes out everything not locked. The draw aims for the blueprint's
cognitive and difficulty mix and, among questions that serve the mix equally, prefers those never
used, then those not used lately, and leaves questions used within the recent-use period for last.
It never puts the same question or the same text in twice. Rows with the least choice are drawn
first, so a row that can only give one level of thinking is not left with the questions the mix
needed from it. It is a heuristic, and the screen shows the mix it reached against the mix asked for.
What the bank cannot supply is left as a gap and reported, row by row.

**By hand.** Questions can be added to a row that has room, swapped for another of the same row,
locked (a new draw leaves them, and they cannot be swapped or removed until unlocked) or taken out.
The picker shows each candidate's text, levels, how often it has been used and when last, and can be
searched. The paper's order is the blueprint's — row by row — and whether each candidate meets
questions and options in an order of their own is set on the paper (both on by default).

**Worth a second look**, in words, beside the paper: the same text twice, a question that gives
away the answer to another (the second's correct answer, long enough not to be a common word,
appears word for word in the first's text or options), a question used within the last 12 months,
your own questions, a newer version in use, a question no longer in the bank, and questions whose
row was changed or removed since. A row's questions are found again when a blueprint is saved by the
row's topic, type, marks and section, so editing an unrelated row leaves the paper alone.

**Who sees what.** Anybody with `exam_papers` View sees a paper's counts and coverage. Its
_questions_ are read only by those whose work needs them — choosing questions, approving or
finalising a paper — so a registry clerk can count a paper without being able to read it.

**Audited:** `paper.created`, `paper.drawn` (with the references drawn), `paper.item_added`,
`paper.item_swapped`, `paper.item_removed`, `paper.item_locked`, `paper.item_unlocked`,
`paper.settings_changed`.

## What step 16 built: moderating, finalising, locking and versions

**The paper has its own workflow**, separate from the blueprint's: draft → submitted → approved →
finalised → published, with "sent back" moving submitted or approved paper back to draft, reason
required. Submitting needs `exam_papers` Edit and a sound paper (the same completeness report the
builder screen already showed in step 15 — every row full, nothing over, no gap left); approving,
sending back and finalising need `exam_papers_approve` / `_finalise`; publishing needs
`exam_papers_publish`; starting a new version needs `exam_papers_unlock`. Nobody approves a paper
they created or submitted themselves — the same rule the blueprint already followed.

**Comments** (`exm_paper_comments`) let the committee read the paper and leave a note against a
question, or a general one, while it is submitted or approved. A comment being open does not block
approval — a moderator may approve with a note still outstanding — but it does block **finalising**:
a paper is only locked once every comment on it is resolved. Comments can be left by anyone who may
read the paper's questions or who may approve it; only the approving right resolves or reopens one.

**Finalising locks it**: it computes and stores a fingerprint (SHA-256, the same pattern as the
blueprint's) of the paper's substance — its items, their order, their marks and how it is presented —
and from that point the database itself refuses any change to its items, comments or settings,
exactly as an approved blueprint already does. Publishing is a separate step and a separate right, so
finalising and releasing a paper for delivery are never the same click.

**Correcting a locked paper** does not touch the finalised or published version: starting a new
version copies its settings and every item into a fresh draft, one version number on, with every
copied item locked (so "fill the gaps" cannot silently swap them out) but free to be replaced by
hand. Old versions stay exactly as they were, read-only, and can be opened again from a version
switcher on the paper screen; nothing is ever deleted.

**What is frozen, and where:** the same trigger-and-stored-function pattern as the blueprint (step 14)
and the question bank's own versions — a submitted, approved, finalised or published paper's items are
refused by the database itself, comments can only be written or resolved while the paper is
moderating, and the paper's examination, version number and shuffle settings are frozen once it
leaves draft. A paper past draft is never deleted, and the state machine (draft → submitted →
approved → finalised → published, with the two "send back" moves) is enforced there too, so a direct
`UPDATE` cannot skip a stage any more than the application can.

**On the paper screen**, what a person may attempt (`can.*`) and whether it would actually succeed
right now (`report.isComplete`, with its blockers and advisories) are kept separate, as they already
are for the blueprint: a button is shown once the right and the stage allow it, and disabled — with
the reason said in words — when the paper itself is not ready.

**Audited:** `paper.submitted`, `paper.approved`, `paper.returned`, `paper.finalised`,
`paper.published`, `paper.version_started`, `paper.comment_added`, `paper.comment_resolved`,
`paper.comment_reopened`.

## What step 17 built: candidates, centres, rooms, allocation and check-in

**Centres and rooms** (`cand_centres`, `cand_rooms`) are campus infrastructure — a hall or a building,
and the rooms in it, with how many candidates each holds — reused across every examination held on
that campus. They are not tied to any one course, so managing them needs only `exam_centres` Edit for
the campus being worked in, not the course-scoped exam access blueprints and papers already check.

**The candidate roster** (`cand_candidates`) is imported once per examination from a CSV file, as
exam-phase.md's settled questions said it would be: candidate number, name and, optionally, roll
number, CNIC, email and phone. A candidate number already on the roster is skipped, not overwritten,
so importing the same file twice — or a corrected file with rows added — is safe. Registering
candidates needs `exam_candidates` Edit.

**Allocation, extra time, the PIN and check-in all live on that one row**, the same way a paper's
moderation columns live on `exm_papers`: a candidate's way to their seat is one small, mostly empty
record until each step happens to it, not a family of joined tables.

- **Allocation** (`centre.allocate`) fills a chosen centre's active rooms, in name order, up to their
  capacity, with every candidate not yet seated; a candidate can also be allocated, or moved, by hand
  into any room. Once a candidate has checked in their seat is fixed — moving them after that is a
  check-in day correction, not an allocation, and is refused.
- **Extra time** (`candidate.extra_time`) is granted ahead of the day with a reason, in minutes; the
  delivery engine (step 18) reads it when it computes a candidate's deadline.
- **Check-in** (`candidate.checkin`) needs a candidate to be allocated first. It issues a one-time
  exam PIN — six digits, generated fresh, shown to the invigilator exactly once in the response — and
  keeps only its hash from then on, checked the way a password is. With their candidate number, this
  is what ADR-0003 lets a candidate resume an exam with on another computer. A PIN can be reissued —
  lost, forgotten, or given to the wrong person — which replaces the hash; the old PIN stops working
  the moment that happens.

**What is frozen, and where:** once a candidate has checked in, their candidate number and which
examination they sat are refused by the database itself, and the row is never deleted — the same
promise the question bank, the blueprint and the paper already make for what they freeze. Everything
else about a candidate (allocation, extra time) can still be corrected; only their identity and the
fact that they sat is locked.

**Conduct Exam**, alongside Create Exam in the CMS's Exams menu, opens on a list of the campus's
examinations; each links to its candidates and its check-in screen, and a "Centres & rooms" link
sits beside it. It opens for anybody who may see candidates, check them in, or monitor delivery —
`exam_candidates`, `exam_checkin` or `exam_monitor` View — the same three rights the architecture
blueprint named for it in step 7.

**Audited:** `candidate.imported`, `candidate.allocated`, `candidate.checked_in`,
`candidate.pin_reissued`, `candidate.extra_time_granted`, `centre.created`, `centre.updated`,
`room.created`, `room.updated`.

## What step 18 built: sitting the exam, and resuming it on another computer

**A candidate is not a kmu-cms account.** They sign in on the examination's own page with their
candidate number and exam PIN (a separate `candidate` guard, never the `web` guard staff use), and
reach only `/sit/...` — never the CMS sidebar, never anything else this module holds.

**The attempt is the record, not the screen.** Signing in for the first time assigns the published
paper's items in this candidate's own order, with their own option order if the paper shuffles them
(`cand_paper_items`) — fixed from that moment on, the database's own promise, not just the
application's. The deadline is the examination's duration plus whatever extra time (step 17) they
were granted, computed once and stored, not recalculated from a client clock.

**One computer at a time (ADR-0003).** Each sign-in opens a `dlv_sessions` row with a heartbeat, sent
by the candidate's browser every 20 seconds:

- A previous session silent for a minute or more (`exam.delivery.session_stale_after_seconds`) is
  treated as crashed: the new sign-in ends it and resumes automatically, and the swap is audited
  (`candidate.device_changed`) — not a misconduct finding, a record.
- A previous session still sending heartbeats blocks the new sign-in outright. There is no separate
  request-and-approve flow for the invigilator: from the monitor screen they end the stuck session
  directly (`delivery.session_control`), and the candidate's very next attempt finds nothing in its
  way.
- Once submitted, no sign-in reaches the exam again — only the read-only submitted screen.

**Every answer is a sequence-numbered event (ADR-0003).** The candidate's browser keeps a small local
journal (in `localStorage`, not just in memory) of every change not yet acknowledged, and resends it
until the server confirms — on a flaky connection, after a reload, or after the crash a resume on
another computer follows. The server records each one append-only (`dlv_answer_events`, the database
itself refusing any update or delete to it) and keeps the current answer per item alongside it
(`dlv_answers_current`) so a resume reads one row per item instead of replaying history. A sequence
number sent twice does nothing the second time; an older one never overwrites a newer answer that
already arrived. **The answer key never reaches the browser**: every column the candidate's screen is
given is content meant to be seen — marking (step 20) reads the key server-side, from the paper's own
items.

**The deadline is enforced on the server, on the next request that touches it** — a heartbeat or an
answer — not by a scheduler: an attempt nobody ever calls back into past its deadline and grace period
(`exam.delivery.grace_seconds`) is auto-submitted the moment anything does. `php artisan
exam:close-expired-attempts` is the safety net for one nobody calls back into at all.

**A room-wide outage is handled by pausing the room** (`delivery.session_control`), which freezes
every running deadline in it at once and adds the outage back when it resumes — rather than each
invigilator working out compensating time by hand afterwards. A single candidate's own outage is
compensating time added to their attempt alone, with a reason, always audited.

**Known simplification, disclosed rather than silently skipped:** sections with their own time
(exam-phase.md's "Section A multiple choice, Section B short answer") are planned at the blueprint
but not separately timed at delivery in this pass — one deadline covers the whole paper. Browser
lockdown, centre device approval and proctoring events are step 19, not this one.

**Audited:** `candidate.exam_started`, `candidate.device_changed`, `candidate.exam_submitted`,
`candidate.session_ended_by_invigilator`, `candidate.room_paused`, `candidate.room_resumed`,
`candidate.compensating_time_granted`.

## What step 19 built: browser lockdown and proctoring events

**Lockdown is enforced by the candidate's own browser**, not a separate client: on starting the
exam it asks for fullscreen, and reports every attempt to leave it, switch tabs, copy, paste,
right-click, print or open developer tools. Each report is a `dlv_proctor_event` — append-only,
severity fixed by its type (`ProctorEventType::severity()`) so the same kind of event always
weighs the same — never a silent block alone. A high-severity event (devtools, print, an
unapproved device) is also written to the tamper-evident audit log.

**Known simplification, disclosed rather than silently skipped**: this is JS-enforced lockdown,
not a Safe Exam Browser (SEB) client integration — there is no browser-exam-key config file to
generate or verify. A determined candidate with local admin rights could defeat JS-only lockdown;
what is guaranteed is that every attempt is *recorded*, for the committee to act on, the same way
the security table below has always promised evidence and a decision rather than an unbreakable
wall.

**Centre device approval.** A device (browser + machine) not seen before at a candidate's centre
is recorded as `cand_devices` and blocks the attempt — the exam screen shows "waiting for your
invigilator" — until an invigilator approves it from **Centres & rooms**. Approval is of the
device, not the candidate: once approved it stays approved for whoever sits at it next, the same
way a room, once set up, is reused across every examination held in it. Turned off with
`EXAM_DEVICE_APPROVAL_REQUIRED=false` for centres that cannot support it (candidates' own
laptops). Known simplification: approval does not pause the candidate's clock while they wait —
the exam's deadline runs from sign-in, as it always has.

**The committee's review** sits in the CMS's Exams menu as **Proctoring**, alongside Monitor: a
list of attempts with any events, each opening onto that candidate's full timeline and a form to
record one of four decisions — no action, a warning, flagged for review, or voiding the attempt.
A decision is itself a permanent record (`dlv_proctor_decisions`), never changed once written, the
same as the event it answers. Voiding is allowed even after the candidate has submitted, since a
case is often only found once marking or a complaint follows — the attempt's own workflow now
allows `submitted → voided` and `in_progress/paused → voided`, enforced by the database the same
way every other step of it already is.

**Who may do what**: `proctor.events.view` / `proctor_events` to see the case list and a
candidate's timeline; `proctor.review.decide` / `proctor_decisions` to record a decision.
Approving a device needs `centre.manage`, the same right that manages centres and rooms — seeing
the pending list only needs `centre.view`.

**Audited:** `proctor.event_recorded` (high severity only), `proctor.decision_recorded`,
`device.seen`, `device.approved`.

## What step 20 built: marking, automatic and rubric-based with two examiners

**Objective items are marked the moment an attempt is submitted.** `AutoMarkAttempt` runs inside
`SubmitAttempt`'s own transaction — the candidate's, the auto-deadline's and the invigilator's
submission paths all go through it, so nothing extra had to be wired up. It reads the sealed key
server-side, exactly where ADR-0003 always said it would be read: `qb_question_options.is_correct`
and `weight` for single- and multiple-response items, `qb_question_items.is_true`/
`correct_option_id`/`marks_fraction` for true/false, matching and EMQ and ordering sub-parts, and
`qb_question_answers` (by `match_mode`: exact, contains, regex or numeric with a tolerance) for
short-answer, numerical and cloze blanks. Essays (`is_manually_marked`) are left untouched for an
examiner.

**Whether an essay needs one examiner or two is the examination's own setting**
(`require_double_marking`, mutable — not one of the fields the blueprint freezes). Examiners are
assigned per examination, not per question: a controller (`marking.assign`) picks a first and
second examiner, and optionally an adjudicator, from the campus's staff who hold `marking.mark` —
every manually-marked item of the paper goes to the same pair. Marking against the rubric
(`qb_question_rubric_criteria`, already built with the question bank) means giving marks per
criterion that add up to the item's mark; a non-rubric manually-marked type takes a flat mark.

**Marking is blind.** An examiner's marking screen shows whether their peer has marked an item yet
— never what they gave — until their own mark for it is in; only then, and only for someone who
holds `marking.adjudicate`, are both numbers shown side by side. This is enforced server-side
(`MarkingController::hidePeerMarkIfBlind`), not just hidden in the UI.

**How the final mark for an item is decided**, by priority, in one place
(`App\Domain\Marking\Queries\FinalMark`) so results (step 21) never disagree with the marking
screens about which mark counts: an adjudicator's mark, if one exists; else an agreed `final` row;
else — only when double-marking is not required — the first examiner's own mark; else the
automatic mark. Two examiners' marks that differ by no more than
`exam.marking.adjudication_threshold_fraction` (10% of the item's marks, by default) are averaged
into that `final` row the moment the second one arrives; beyond it, the item sits pending until an
adjudicator's mark — itself final, no further review — arrives. Adjudication is allowed even after
the candidate has submitted and been marked once already, since a case is often only found once
marking is under way.

**What is never changed, and where:** a mark, once recorded, is a fact — `mrk_item_marks` and its
rubric breakdown (`mrk_item_mark_criteria`) refuse `UPDATE` and `DELETE` at the database itself, the
same pattern proctoring's own log already uses. Assignments are not append-only: reassigning a role
replaces the row, since who marks is a roster, not a record of what happened.

**Known simplification, disclosed rather than silently skipped:** an examiner is chosen from staff
who already have a kmu-cms account in this app (someone who has signed in via SSO at least once) —
someone with the right CMS checkbox who has never opened the module cannot yet be assigned, unlike
`BlueprintApprovers`' "ask them anyway" list for blueprint approval.

**Who may do what** (new checkboxes, added the same way the `proctor_*` ones were):
`marking.assign` / `exam_marking_assign` to assign examiners; `marking.mark` / `exam_marking` to
mark; `marking.adjudicate` / `exam_marking_adjudicate` to adjudicate. **Marking**, in the CMS's Exams
menu, opens for anybody holding any of the three.

**Audited:** `marking.examiner_assigned`, `marking.item_finalised`, `marking.adjudicated`.

## What step 21 built: results, pass mark, approval and publication

**A result is compiled, not stored as its own fact.** `CompileResult` sums, for every item of an
attempt, `App\Domain\Marking\Queries\FinalMark::of()` — the exact same priority step 20 built for
the marking screens, so results never disagree with marking about which mark counts for an item.
If any item has no final mark yet, the attempt is `pending_items` and has no total — it cannot be
approved until every one of its items is. The compiled numbers (`exm_results`) are a cache,
recomputed every time this runs, not an append-only record: correcting a mark is meant to change
the number, not be fought by it.

**Negative marking applies only where there is a key to be wrong against**: an item whose final
mark came from `auto` (never an examiner's or an adjudicator's judgement, never an essay), scored
zero, that the candidate actually answered — a blank is not a guess, so a blank is never penalised.
The deduction is `examination.negative_fraction × the item's marks`, summed and subtracted from the
raw total, floored at zero. This was already a setting on the examination
(`negative_marking`/`negative_fraction`) that nothing applied until now.

**Approval and publication are two separate rights over the whole examination**, all its attempts
at once — the same granularity the blueprint and paper are already approved at, not
candidate-by-candidate. Approving recompiles every submitted attempt first, so it can never approve
a stale number, and refuses outright while anything is still `pending_items`. Publishing needs
approval to have happened first. Both are recorded on `exm_result_publications`, one row per
examination, not on the results themselves.

**Re-keying corrects the paper's own item, never the reusable question in the bank.** The bank's
record was reviewed and approved on its own merits and is left exactly as it was — a correction
here is scoped to the one paper it was found faulty in. Two decisions are offered: discard it
(full marks to everyone who sat it) or, for a plain single/multi-select item with no sub-parts,
say which option is actually correct. Sub-item types (matching, true/false sets, cloze, ordering)
can only be discarded — disclosed rather than silently narrowed, the same way step 18/19 disclosed
their own simplifications. An essay has no key to re-key at all; that is a marking dispute, handled
by marking it again, not this.

**Rescoring is automatic and exhaustive**: re-keying writes one `mrk_item_marks` row per affected
candidate with `source = rekeyed` — a new mark source that outranks even an adjudicator's, because
it corrects the question, not the marking — using the exact scoring logic `AutoMarkAttempt` itself
uses (`App\Domain\Marking\Support\ObjectiveItemScorer`, extracted from step 20's own code so the
two can never quietly disagree). Every affected attempt is recompiled immediately. If the
examination's results were already approved or published, that drops back to draft: a correction
found after publication has to be looked at again before anyone re-publishes it.

**What is never changed, and where:** a re-key, once written (`exm_item_rekeys`), refuses `UPDATE`
and `DELETE` at the database itself — the same append-only pattern every other decision log in this
module already follows. Re-keying the same item twice is refused outright, in words: a second
problem with an item is a fresh decision, not a retry of the first.

**Known simplification, disclosed rather than silently skipped:** results are staff-only in this
pass. There is no candidate-facing screen yet showing a published result — the security table's
"no result is visible before it is approved and published" is enforced here as "no result is
computed or shown to staff before then," and a candidate-facing view is left for later.

**Who may do what** (checkboxes already pre-seeded back in step 7, unlike step 20's `marking.*`):
`result.view` / `exam_results`; `result.approve` / `exam_results_approve`; `result.publish` /
`exam_results_publish`; `result.rescore` / `exam_results_rescore` for re-keying. **Results & Marks**
— the menu item this doc named from the start — now opens for anybody holding any of the four.

**Audited:** `result.approved`, `result.published`, `result.rekeyed` (with the before and after
mark for every affected attempt).

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
| 14   | The three menu items and their deep links; blueprint tables and screen | both       | Done    |
| 15   | The examination, and building a paper from the QBank                   | kmu-assess | Done    |
| 16   | Moderation, finalising, locking and paper versions                     | kmu-assess | Done    |
| 17   | Candidates, centres, rooms, allocation, check-in and PINs              | kmu-assess | Done    |
| 18   | The delivery engine, including resuming on another computer (ADR-0003) | kmu-assess | Done    |
| 19   | Browser lockdown and proctoring events                                 | kmu-assess | Done    |
| 20   | Marking: automatic, and rubric-based with two examiners                | kmu-assess | Done    |
| 21   | Results: pass mark, approval and publication                           | kmu-assess | Done    |
| 22   | Post-hoc analysis, and the decision going back to the question bank    | kmu-assess | Planned |
