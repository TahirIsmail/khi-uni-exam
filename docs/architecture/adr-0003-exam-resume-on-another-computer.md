# ADR-0003 — A candidate's exam survives a crash or network loss and resumes on another computer

Status: Accepted (17 Sep 2026). Implemented in step 18 (see exam-phase.md, "What step 18 built").

## Decision

If the candidate's computer shuts down, the browser crashes or the network drops, the candidate
signs in on another computer with their candidate number and exam PIN and **continues the same
attempt**: same paper, same question and option order, all saved answers, the question they were
on, and the remaining time calculated by the server.

## How it works

- **The server is the record.** Every answer change is saved to the server within about a second
  (sequence-numbered, idempotent autosave) and also kept in the browser's local journal until the
  server acknowledges it. The candidate sees a "Saved" indicator.
- **The attempt is stored, not the screen.** The assigned paper version, per-candidate question
  order and option order, answers, flagged questions, last viewed question and timer
  (`started_at`, `deadline_at`, extensions, pauses) all live in the database, so any computer can
  rebuild the exact exam.
- **One active computer at a time.** Each sign-in creates a delivery session with a heartbeat.
  When the candidate signs in on a new computer:

    | Previous computer                                       | Result                                                                                                                                                   |
    | ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
    | Silent for 60 s or more (crashed, powered off, offline) | Resume automatically on the new computer. The old session token is revoked and a `device_changed` event is logged for review (not a misconduct finding). |
    | Still sending heartbeats                                | Blocked with "This exam is open on another computer". The invigilator can approve the move. This stops two people using one ID.                          |
    | Attempt submitted, or deadline + grace passed           | No resume; the candidate sees that the exam is submitted.                                                                                                |

    In exam-centre mode the new computer must also be an approved centre device (Safe Exam Browser
    key and seat/IP checks).

- **Time.** The exam clock keeps running on the server during a single candidate's outage.
  The invigilator can add compensating time with a reason. A room-wide outage is handled by
  pausing the room, which freezes every deadline in it.
- **What can be lost.** Only answers changed in the last moment before a crash that never
  reached the server (normally under 2 seconds of work). If the old computer comes back online,
  its unsent journal entries are still accepted when their sequence numbers are newer and they
  were made before the deadline.

## Data (as built in step 18)

`cand_candidate_exams` (status, paper version, timer, `last_item_id`) — the timer (`deadline_at`,
`paused_at`, `extra_seconds`) lives on this same row rather than a separate `dlv_timers` table, and
it stands in for the `cand_papers` record too, since one candidate has exactly one paper per
examination. `cand_paper_items` (order and option order). `dlv_sessions` (token hash, device,
heartbeat, end reason). `dlv_answer_events` (append-only, unique per sequence number).
`dlv_answers_current`. `dlv_submissions`.

## Tests (done — see tests/Feature/Exam/DeliveryTest.php and tests/browser/exam_delivery.mjs)

Power off mid-exam and resume on another computer with all acknowledged answers, the flag and the
correct remaining time; attempt a second sign-in while the first computer is alive (blocked); an
invigilator ending the stuck session so the candidate may resume; a replayed autosave changing
nothing and an older, out-of-order one never overwriting a newer answer; no resume after submission;
the database itself refusing a change to an answer event, to a submitted attempt's answers, or to a
candidate's paper once the attempt has started; a room pause freezing every deadline in it.
