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
        'session_stale_after_seconds' => env('EXAM_SESSION_STALE_SECONDS', 60),
        // How often the candidate's browser is expected to send a heartbeat.
        'heartbeat_interval_seconds' => 20,
        // Sitting past the deadline by this much still autosaves and submits; after it, the attempt
        // is auto-submitted as it stands.
        'grace_seconds' => env('EXAM_GRACE_SECONDS', 120),
    ],

];
