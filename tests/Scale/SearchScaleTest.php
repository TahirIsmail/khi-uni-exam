<?php

/*
 * Criterion 10 of the increment: a search that combines course, topic, level of thinking,
 * difficulty, status and author answers in under a second on a bank of 50,000 versions.
 *
 *   php artisan test tests/Scale
 *
 * This file is deliberately outside tests/Feature, so it runs without the transaction the rest of
 * the suite uses: MySQL only fills a FULLTEXT index for rows that are committed, and a search that
 * cannot use its index would prove nothing. It writes its 50,000 questions into the testing
 * database under a campus of its own and removes them again, whatever happens.
 */

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Queries\QuestionList;
use App\Support\Html\QuestionHtml;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCms;

uses(InteractsWithCms::class);

const SCALE_VERSIONS = 50_000;
const SCALE_BUDGET_MS = 1000;

/**
 * Runs the teardown with the named guards lifted, then puts them back exactly as they were. The
 * bank refuses to delete questions and non-draft versions — rightly — so a fixture of this size
 * can only be cleared this way, and only in the testing database.
 *
 * @param  list<string>  $triggers
 */
function withoutTriggers(array $triggers, callable $work): void
{
    $statements = [];
    foreach ($triggers as $trigger) {
        $row = DB::selectOne("SHOW CREATE TRIGGER `{$trigger}`"); // raw-sql-reviewed: names are literals in this file
        if ($row !== null) {
            $statements[$trigger] = (string) $row->{'SQL Original Statement'};
            DB::unprepared("DROP TRIGGER `{$trigger}`"); // raw-sql-reviewed: names are literals in this file
        }
    }

    try {
        $work();
    } finally {
        foreach ($statements as $statement) {
            DB::unprepared($statement);
        }
    }
}

/**
 * Clears everything a scale run creates — its campus in the stand-in CMS database, the courses,
 * topics, staff and module users under it, and its questions. Used before building the fixture (a
 * run that was interrupted leaves half of one behind) and again when the test is finished.
 */
function clearScaleFixture(string $cms): void
{
    $branches = DB::table("{$cms}.branches")->where('branch_name', 'Scale Campus')->pluck('id');
    $staffIds = $branches->isEmpty() ? collect() : DB::table("{$cms}.staff")->whereIn('branch_id', $branches)->pluck('id');
    $userIds = $staffIds->isEmpty() ? collect() : DB::table('users')->whereIn('cms_staff_id', $staffIds)->pluck('id');

    $questionIds = DB::table('qb_questions')
        ->where(fn ($query) => $query->whereIn('branch_id', $branches->isEmpty() ? [0] : $branches->all())
            ->orWhere('public_ref', 'like', 'Q-2026-9%'))
        ->pluck('id');

    withoutTriggers(['trg_qb_questions_no_delete', 'trg_qb_versions_delete_drafts_only'], function () use ($questionIds, $userIds): void {
        if ($questionIds->isNotEmpty()) {
            DB::table('qb_questions')->whereIn('id', $questionIds)->update(['active_version_id' => null]);
            DB::table('qb_question_versions')->whereIn('question_id', $questionIds)->delete();
            DB::table('qb_questions')->whereIn('id', $questionIds)->delete();
        }

        if ($userIds->isNotEmpty()) {
            DB::table('sec_audit_logs')->whereIn('actor_id', $userIds)->update(['actor_id' => null]);
            DB::table('users')->whereIn('id', $userIds)->delete();
        }
    });

    if ($branches->isEmpty()) {
        return;
    }

    $programmes = DB::table("{$cms}.classes")->whereIn('branch_id', $branches)->pluck('id');
    $professionals = $programmes->isEmpty() ? collect() : DB::table("{$cms}.acad_professionals")->whereIn('class_id', $programmes)->pluck('id');
    $courses = $professionals->isEmpty() ? collect() : DB::table("{$cms}.acad_courses")->whereIn('professional_id', $professionals)->pluck('id');

    if ($courses->isNotEmpty()) {
        DB::table("{$cms}.acad_curriculum_nodes")->whereIn('course_id', $courses)->delete();
        DB::table("{$cms}.acad_courses")->whereIn('id', $courses)->delete();
    }
    if ($professionals->isNotEmpty()) {
        DB::table("{$cms}.acad_professional_terms")->whereIn('professional_id', $professionals)->delete();
        DB::table("{$cms}.acad_professionals")->whereIn('id', $professionals)->delete();
    }
    if ($programmes->isNotEmpty()) {
        DB::table("{$cms}.acad_level_templates")->whereIn('class_id', $programmes)->delete();
        DB::table("{$cms}.acad_programme_profiles")->whereIn('class_id', $programmes)->delete();
        DB::table("{$cms}.classes")->whereIn('id', $programmes)->delete();
    }
    if ($staffIds->isNotEmpty()) {
        DB::table("{$cms}.acad_staff_exam_scopes")->whereIn('staff_id', $staffIds)->delete();
        DB::table("{$cms}.staff_roles")->whereIn('staff_id', $staffIds)->delete();
        DB::table("{$cms}.staff")->whereIn('id', $staffIds)->delete();
    }

    DB::table("{$cms}.roles")->where('name', 'Scale faculty')->delete();
    DB::table("{$cms}.branches")->whereIn('id', $branches)->delete();
}

/** The words a question of this teaching set is about, so searches have something to match. */
function scaleTopicWords(int $i): string
{
    $subjects = ['chest pain', 'breathlessness', 'abdominal pain', 'headache', 'joint swelling', 'jaundice', 'palpitations', 'haematuria'];
    $findings = ['ST elevation', 'a pleural effusion', 'rebound tenderness', 'papilloedema', 'a warm effusion', 'pale stools', 'an irregular pulse', 'red cell casts'];

    return $subjects[$i % count($subjects)].' with '.$findings[($i * 7) % count($findings)];
}

test('a search combining course, topic, thinking, difficulty, status and author answers in under a second on 50,000 versions', function () {
    $this->shareCmsConnection();

    // The schema, in case this file is run on its own.
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $cms = config('database.cms_source_database');

    // A run that was interrupted leaves a half-built fixture behind; clear it before building one.
    clearScaleFixture($cms);

    $branch = $this->cmsBranch('Scale Campus');
    $programme = $this->cmsProgramme($branch, 'MBBS');
    $professional = $this->cmsProfessional($programme);
    $courses = [];
    $nodes = [];
    for ($c = 0; $c < 5; $c++) {
        $courses[$c] = $this->cmsCourse($programme, $professional, 'SCALE'.$c);
        for ($n = 0; $n < 4; $n++) {
            $nodes[$courses[$c]][$n] = $this->cmsCurriculumNode($courses[$c], $programme, 'Topic '.$c.'.'.$n);
        }
    }

    $role = $this->cmsRole('Scale faculty');
    $this->cmsGrant($role, 'qbank_questions', 'view', 'add');
    $authors = [
        $this->staffUser([$role], $branch),
        $this->staffUser([$role], $branch),
        $this->staffUser([$role], $branch),
    ];
    app(AccessControl::class)->forget($authors[0]);

    $statuses = [VersionStatus::Draft, VersionStatus::Submitted, VersionStatus::UnderReview, VersionStatus::Active, VersionStatus::Active];
    $typeId = (int) DB::table('qb_question_types')->where('code', 'single_best_answer')->value('id');
    $seeded = 0;

    try {
        $questionId = ((int) DB::table('qb_questions')->max('id')) + 1;
        $now = now()->format('Y-m-d H:i:s');

        // 1,000 at a time: one insert per chunk into each table (MySQL takes at most 65,535 values
        // in one statement), so seeding is a minute rather than an hour.
        for ($offset = 0; $offset < SCALE_VERSIONS; $offset += 1_000) {
            $questions = [];
            $versions = [];

            for ($i = $offset; $i < $offset + 1_000; $i++) {
                $course = $courses[$i % 5];
                $node = $nodes[$course][($i >> 2) % 4];
                $author = $authors[$i % 3];
                // Not keyed on the same number as the course, so every course has every status.
                $status = $statuses[intdiv($i, 5) % 5];
                $stem = '<p>A '.(20 + $i % 60).'-year-old patient has '.scaleTopicWords($i).' for '.(1 + $i % 9).' days.</p>';
                $id = $questionId + ($i - $offset);

                $questions[] = [
                    'id' => $id,
                    'public_ref' => sprintf('Q-2026-9%05d', $i),
                    'branch_id' => $branch,
                    'course_id' => $course,
                    'latest_version_no' => 1,
                    'created_by' => $author->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $versions[] = [
                    'question_id' => $id,
                    'version_no' => 1,
                    'question_type_id' => $typeId,
                    'branch_id' => $branch,
                    'stem' => $stem,
                    'lead_in' => 'Which investigation is most useful first?',
                    'marks' => 1,
                    'negative_marks' => 0,
                    'programme_id' => $programme,
                    'professional_id' => $professional,
                    'course_id' => $course,
                    'node_id' => $node,
                    'cognitive_level_id' => 1 + $i % 4,
                    'difficulty_level_id' => 1 + $i % 3,
                    'status' => $status->value,
                    'content_hash' => hash('sha256', $stem.$i),
                    'search_text' => QuestionHtml::toText($stem).' Which investigation is most useful first?',
                    'source' => 'manual',
                    'author_id' => $author->id,
                    'created_by' => $author->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('qb_questions')->insert($questions);
            DB::table('qb_question_versions')->insert($versions);
            $questionId += 1_000;
            $seeded += 1_000;
        }

        // Each question's newest version is its only one, which is what the search joins on.
        DB::statement('UPDATE qb_questions q JOIN qb_question_versions v ON v.question_id = q.id SET q.active_version_id = v.id WHERE q.branch_id = ?', [$branch]); // raw-sql-reviewed: bound value

        expect(DB::table('qb_question_versions')->where('branch_id', $branch)->count())->toBe(SCALE_VERSIONS);

        $list = app(QuestionList::class);
        $user = $authors[0];
        $time = function (callable $run): array {
            $started = hrtime(true);
            $result = $run();

            return [$result, (int) ((hrtime(true) - $started) / 1_000_000)];
        };

        // The combination the criterion names.
        [$page, $combined] = $time(fn () => $list->paginate($user, $branch, [
            'course_id' => $courses[1],
            'node_id' => $nodes[$courses[1]][2],
            'cognitive_level_id' => 2,
            'difficulty_level_id' => 2,
            // KMU's own status: "Accept" covers approved and in use.
            'status' => 'accept',
            'author_id' => $authors[1]->id,
            'sort' => 'updated',
        ]));

        // The same, with words to match as well.
        [$searched, $withWords] = $time(fn () => $list->paginate($user, $branch, [
            'search' => 'chest pain ST elevation',
            'course_id' => $courses[1],
            'status' => 'accept',
        ]));

        [$counts, $countsMs] = $time(fn () => $list->statusCounts($user, $branch));

        // Every row that came back really matches, and the timings are inside the budget.
        foreach ($page->items() as $row) {
            expect($row['status'])->toBeIn([VersionStatus::Approved->value, VersionStatus::Active->value]);
        }

        expect($page->total())->toBeGreaterThan(0)
            ->and($searched->total())->toBeGreaterThan(0)
            ->and(array_sum($counts))->toBe(SCALE_VERSIONS)
            ->and($combined)->toBeLessThan(SCALE_BUDGET_MS)
            ->and($withWords)->toBeLessThan(SCALE_BUDGET_MS)
            ->and($countsMs)->toBeLessThan(SCALE_BUDGET_MS);

        fwrite(STDERR, sprintf(
            "\n  50,000 versions: filters %d ms, filters + words %d ms, status counts %d ms (budget %d ms)\n",
            $combined,
            $withWords,
            $countsMs,
            SCALE_BUDGET_MS,
        ));
    } finally {
        // Whatever happened, the testing database goes back to how it was.
        clearScaleFixture($cms);
    }

    expect($seeded)->toBe(SCALE_VERSIONS);
})->group('scale');
