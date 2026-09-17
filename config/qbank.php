<?php

return [

    /*
     * Limits and house rules for question content. These are institution choices, not code:
     * they are checked when a question is saved and shown in the editor.
     */
    'stem' => [
        'min_length' => 10,
        'max_length' => 3000,
    ],

    'marks' => [
        'max' => 100,
    ],

    // A question cannot be submitted for review without at least one reference.
    'require_reference' => env('QBANK_REQUIRE_REFERENCE', false),

    // Warnings from the item-writing checklist (blueprint 10.3, based on the NBME guide).
    'checklist' => [
        'flag_all_of_the_above' => true,
        'flag_negative_lead_in' => true,
        'flag_longest_option_is_key' => true,
        'flag_absolute_terms' => true,
        'flag_missing_explanation' => true,
    ],

    'media' => [
        'max_size_kb' => 5120,
        'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

];
