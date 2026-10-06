<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Question bank lookups (blueprint 8.1): the question types the editor can offer, with what each
 * one is made of and which settings it supports, plus the cognitive and difficulty scales.
 *
 * A type row describes its shape so one set of tables serves every type:
 *  - shared options (A, B, C ...): single best answer, multiple response, true/false, matching, EMQ
 *  - items (sub-parts): true/false statements, EMQ lead-ins, matching prompts, cloze blanks, ordering items
 *  - accepted answers: short answer, numerical, cloze blanks
 *  - a rubric and manual marking: essay
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qb_question_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->autoIncrement();
            $table->string('code', 40)->unique()->comment('used in code and imports');
            $table->string('name', 80);
            $table->string('family', 20)->comment('choice, matching, text, numeric, essay, composite');
            $table->string('description', 255);

            $table->boolean('has_options')->default(false)->comment('a shared option list (A, B, C ...)');
            $table->unsignedTinyInteger('options_min')->default(0);
            $table->unsignedTinyInteger('options_max')->default(0);
            $table->unsignedTinyInteger('correct_min')->default(0)->comment('correct options required');
            $table->unsignedTinyInteger('correct_max')->nullable()->comment('null = any number');

            $table->boolean('has_items')->default(false)->comment('sub-parts: statements, lead-ins, prompts, blanks');
            $table->unsignedTinyInteger('items_min')->default(0);
            $table->unsignedTinyInteger('items_max')->default(0);
            $table->enum('item_answer', ['none', 'boolean', 'option', 'text', 'position'])->default('none')->comment('how an item is answered');

            $table->boolean('has_accepted_answers')->default(false)->comment('typed answers matched against a list');
            $table->boolean('has_numeric_answer')->default(false);
            $table->boolean('is_manually_marked')->default(false);
            $table->boolean('supports_shuffle')->default(false);
            $table->boolean('supports_partial_credit')->default(false);
            $table->boolean('supports_negative_marks')->default(true);
            $table->boolean('supports_rubric')->default(false);
            $table->boolean('supports_media')->default(true);
            $table->json('default_settings')->comment('type settings with their defaults; the editor renders these');

            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('qb_cognitive_levels', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->autoIncrement();
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('qb_difficulty_levels', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->autoIncrement();
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->decimal('expected_score_from', 5, 4)->nullable()->comment('cut points the committee uses, 0-1');
            $table->decimal('expected_score_to', 5, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        foreach (self::types() as $order => $type) {
            DB::table('qb_question_types')->insert(array_merge([
                'sort_order' => $order + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], $type, ['default_settings' => json_encode($type['default_settings'])]));
        }

        foreach ([
            ['recall', 'Recall / Remember', 'Facts and definitions'],
            ['understand', 'Understanding', 'Explaining in own words'],
            ['apply', 'Application', 'Using knowledge in a new situation'],
            ['analyse', 'Analysis', 'Interpreting data, comparing options'],
            ['evaluate', 'Evaluation', 'Judging between options with criteria'],
            ['create', 'Synthesis / Create', 'Producing a plan or explanation'],
        ] as $order => [$code, $name, $description]) {
            DB::table('qb_cognitive_levels')->insert([
                'code' => $code, 'name' => $name, 'description' => $description,
                'sort_order' => $order + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach ([
            ['easy', 'Easy', 0.7000, 1.0000],
            ['moderate', 'Moderate', 0.4000, 0.6999],
            ['difficult', 'Difficult', 0.0000, 0.3999],
        ] as $order => [$code, $name, $from, $to]) {
            DB::table('qb_difficulty_levels')->insert([
                'code' => $code, 'name' => $name, 'expected_score_from' => $from, 'expected_score_to' => $to,
                'sort_order' => $order + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qb_difficulty_levels');
        Schema::dropIfExists('qb_cognitive_levels');
        Schema::dropIfExists('qb_question_types');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function types(): array
    {
        $shuffle = ['shuffle_options' => true, 'show_option_labels' => true];

        return [
            [
                'code' => 'single_best_answer', 'name' => 'Single best answer (MCQ)', 'family' => 'choice',
                'description' => 'One stem with options; exactly one is correct.',
                'has_options' => true, 'options_min' => 2, 'options_max' => 10, 'correct_min' => 1, 'correct_max' => 1,
                'supports_shuffle' => true, 'default_settings' => $shuffle + ['per_option_feedback' => false],
            ],
            [
                'code' => 'multiple_response', 'name' => 'Multiple response (MRQ)', 'family' => 'choice',
                'description' => 'Several options may be correct; marks can be shared between them.',
                'has_options' => true, 'options_min' => 3, 'options_max' => 12, 'correct_min' => 1, 'correct_max' => null,
                'supports_shuffle' => true, 'supports_partial_credit' => true,
                'default_settings' => $shuffle + ['partial_credit' => true, 'penalise_wrong_choices' => true, 'require_exact_set' => false],
            ],
            [
                'code' => 'true_false', 'name' => 'True / False', 'family' => 'choice',
                'description' => 'A statement that is either true or false.',
                'has_options' => true, 'options_min' => 2, 'options_max' => 2, 'correct_min' => 1, 'correct_max' => 1,
                'default_settings' => ['show_option_labels' => false],
            ],
            [
                'code' => 'multiple_true_false', 'name' => 'Multiple true / false', 'family' => 'composite',
                'description' => 'A stem with several statements, each marked true or false.',
                'has_items' => true, 'items_min' => 2, 'items_max' => 10, 'item_answer' => 'boolean',
                'supports_partial_credit' => true,
                'default_settings' => ['partial_credit' => true, 'penalise_wrong_statements' => true, 'shuffle_statements' => false],
            ],
            [
                'code' => 'extended_matching', 'name' => 'Extended matching (EMQ)', 'family' => 'matching',
                'description' => 'One option list shared by several lead-ins; each lead-in has one answer.',
                'has_options' => true, 'options_min' => 4, 'options_max' => 26, 'correct_min' => 0, 'correct_max' => null,
                'has_items' => true, 'items_min' => 2, 'items_max' => 10, 'item_answer' => 'option',
                'supports_shuffle' => true, 'supports_partial_credit' => true,
                'default_settings' => $shuffle + ['partial_credit' => true, 'options_can_repeat' => true],
            ],
            [
                'code' => 'matching', 'name' => 'Matching pairs', 'family' => 'matching',
                'description' => 'Prompts on the left matched to answers on the right.',
                'has_options' => true, 'options_min' => 2, 'options_max' => 20, 'correct_min' => 0, 'correct_max' => null,
                'has_items' => true, 'items_min' => 2, 'items_max' => 20, 'item_answer' => 'option',
                'supports_shuffle' => true, 'supports_partial_credit' => true,
                'default_settings' => $shuffle + ['partial_credit' => true, 'extra_distractor_answers' => true],
            ],
            [
                'code' => 'ordering', 'name' => 'Put in order', 'family' => 'composite',
                'description' => 'Steps or events the candidate puts in the right order.',
                'has_items' => true, 'items_min' => 3, 'items_max' => 12, 'item_answer' => 'position',
                'supports_shuffle' => true, 'supports_partial_credit' => true,
                'default_settings' => ['partial_credit' => true, 'shuffle_items' => true, 'credit_adjacent_pairs' => true],
            ],
            [
                'code' => 'short_answer', 'name' => 'Short answer', 'family' => 'text',
                'description' => 'A word or phrase typed in, matched against accepted answers.',
                'has_accepted_answers' => true, 'supports_partial_credit' => true,
                'default_settings' => ['case_sensitive' => false, 'trim_whitespace' => true, 'allow_regex' => false, 'max_length' => 120],
            ],
            [
                'code' => 'numerical', 'name' => 'Numerical', 'family' => 'numeric',
                'description' => 'A number, accepted within a tolerance, with an optional unit.',
                'has_accepted_answers' => true, 'has_numeric_answer' => true, 'supports_partial_credit' => true,
                'default_settings' => ['tolerance_type' => 'absolute', 'require_unit' => false, 'decimal_places' => null],
            ],
            [
                'code' => 'cloze', 'name' => 'Fill in the blanks (cloze)', 'family' => 'composite',
                'description' => 'A passage with blanks; each blank is typed in or chosen from a list.',
                'has_options' => true, 'options_min' => 0, 'options_max' => 26, 'correct_min' => 0, 'correct_max' => null,
                'has_items' => true, 'items_min' => 1, 'items_max' => 20, 'item_answer' => 'text',
                'has_accepted_answers' => true, 'supports_partial_credit' => true,
                'default_settings' => ['case_sensitive' => false, 'partial_credit' => true, 'blank_marker' => '[[1]]'],
            ],
            [
                'code' => 'essay', 'name' => 'Long answer / essay', 'family' => 'essay',
                'description' => 'Written answer marked by a person, with an optional rubric.',
                'is_manually_marked' => true, 'supports_rubric' => true, 'supports_negative_marks' => false,
                'default_settings' => ['min_words' => null, 'max_words' => 500, 'allow_attachments' => false, 'attachment_types' => ['pdf', 'png', 'jpg'], 'show_word_count' => true],
            ],
            [
                'code' => 'image_labelling', 'name' => 'Label the image', 'family' => 'composite',
                'description' => 'Points on a picture that the candidate names or matches.',
                'has_options' => true, 'options_min' => 2, 'options_max' => 26, 'correct_min' => 0, 'correct_max' => null,
                'has_items' => true, 'items_min' => 1, 'items_max' => 20, 'item_answer' => 'option',
                'supports_partial_credit' => true,
                'default_settings' => ['partial_credit' => true, 'marker_style' => 'numbered', 'options_can_repeat' => false],
            ],
        ];
    }
};
