<?php

return [

    /*
     * Institution choices for examinations, not code: they are checked when an examination or its
     * blueprint is saved and shown on the screens.
     */

    // The time zone examination dates and times are entered and shown in. They are stored in UTC.
    'timezone' => env('EXAM_TIMEZONE', 'Asia/Karachi'),

    'duration_minutes' => [
        'min' => 5,
        'max' => 720,
    ],

    'total_marks' => [
        'max' => 1000,
    ],

    'pass_percentage_default' => 50,

    'paper' => [
        // A question used in an examination within this many months is offered last, and flagged.
        'recent_use_months' => 12,
        // How many questions the picker lists at a time.
        'candidates_limit' => 30,
        // A correct answer shorter than this is too common to tell whether another question gives it away.
        'cue_min_length' => 8,
    ],

    'blueprint' => [
        // Questions a single row may ask for, and rows and sections a blueprint may have.
        'max_count_per_row' => 500,
        'max_rows' => 200,
        'max_sections' => 10,
        // The planned marks must equal the examination's total marks, give or take this much.
        'marks_tolerance' => 0.005,
        // A blueprint cannot be submitted or approved while the question bank holds fewer questions than
        // a row asks for: a paper could not be built to it. Set to false to make it a warning only.
        'require_questions_in_bank' => env('EXAM_REQUIRE_QUESTIONS_IN_BANK', true),
    ],

    'delivery' => [
        // A previous computer silent for this long is treated as crashed: the next sign-in resumes
        // automatically. Silent for less, and it is treated as still working: the sign-in is blocked
        // until an invigilator ends that session (ADR-0003).
        'session_stale_after_seconds' => env('EXAM_SESSION_STALE_SECONDS', 90),
        // How often the candidate's browser sends a heartbeat (each adds a few random seconds, so a
        // hall of thousands does not knock at the same moment). Keep it well under the stale time.
        'heartbeat_interval_seconds' => (int) env('EXAM_HEARTBEAT_SECONDS', 30),
        // Sitting past the deadline by this much still autosaves and submits; after it, the attempt
        // is auto-submitted as it stands.
        'grace_seconds' => env('EXAM_GRACE_SECONDS', 120),
        // A device (browser + machine) not seen before at a candidate's centre needs an
        // invigilator's approval before the attempt may proceed. Set to false where centres cannot
        // support that (e.g. candidates' own laptops).
        'device_approval_required' => env('EXAM_DEVICE_APPROVAL_REQUIRED', true),
        // How long proctoring evidence (events, decisions) is kept before it may be purged.
        'proctor_evidence_retention_days' => env('EXAM_PROCTOR_RETENTION_DAYS', 365),
    ],

    'marking' => [
        // Two examiners' marks for an item that differ by more than this fraction of the item's
        // marks go to a third opinion (adjudication) instead of being averaged.
        'adjudication_threshold_fraction' => env('EXAM_ADJUDICATION_THRESHOLD_FRACTION', 0.1),
    ],

    'analytics' => [
        // Discrimination and reliability figures are reported as unavailable, not a meaningless
        // number, below this many candidates.
        'min_candidates' => env('EXAM_ANALYTICS_MIN_CANDIDATES', 10),

        // The plain-word bands on the analysis screen (IQQUIK Phase I, docs/plan-qbank-categories.md
        // §2.3). These are the usual published figures; KMU may set its own.
        'difficulty' => [
            'too_hard_below' => (float) env('EXAM_ANALYTICS_DIFFICULTY_LOW', 0.30),   // fewer right than this: too hard
            'too_easy_above' => (float) env('EXAM_ANALYTICS_DIFFICULTY_HIGH', 0.80),  // more right than this: too easy
        ],
        'discrimination' => [
            'good_from' => (float) env('EXAM_ANALYTICS_DISCRIMINATION_GOOD', 0.30),
            'acceptable_from' => (float) env('EXAM_ANALYTICS_DISCRIMINATION_ACCEPTABLE', 0.20),
            // Below 0 is always a red flag: weaker candidates did better than stronger ones.
        ],
        // A wrong option chosen by fewer candidates than this is not doing its job (non-functional).
        'functional_distractor_share' => (float) env('EXAM_ANALYTICS_NFD_SHARE', 0.05),
        'reliability' => [
            'good_from' => (float) env('EXAM_ANALYTICS_RELIABILITY_GOOD', 0.80),
            'acceptable_from' => (float) env('EXAM_ANALYTICS_RELIABILITY_ACCEPTABLE', 0.70),
        ],
    ],

];
