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

    // References and the explanation are optional (KMU): nothing about them stops a question being
    // sent for review, and their absence is not flagged either.

    // Warnings from the item-writing checklist (blueprint 10.3, based on the NBME guide).
    'checklist' => [
        'flag_all_of_the_above' => true,
        'flag_negative_lead_in' => true,
        'flag_longest_option_is_key' => true,
        'flag_absolute_terms' => true,
        'flag_missing_explanation' => false,
    ],

    'media' => [
        'max_size_kb' => 5120,
        'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

];
