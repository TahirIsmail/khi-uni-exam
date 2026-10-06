<?php

namespace App\Console\Commands;

use App\Domain\Candidate\Actions\ImportCandidates;
use App\Domain\Exam\Actions\DrawWholeCoursePaper;
use App\Domain\Exam\ExaminationInput;
use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\QuestionBank\Import\CheckImport;
use App\Domain\QuestionBank\Import\CommitImport;
use App\Domain\QuestionBank\Models\Media;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\Tag;
use App\Models\User;
use App\Support\Admin\AcademicStructure;
use App\Support\Admin\AdminTables;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The BS Computer Science entry test, set up for testing on a fresh server: the programme and its
 * entry-test course with the subjects as topics, the 2023 G-1 / G-2 / G-3 papers imported through
 * the question bank's own import (database/demo/bscs-2023), a trial examination with 10 test
 * candidates, and the 7 October examination with 200 test candidates.
 *
 * Testing only: the imported questions are made active without being reviewed, and the candidates
 * are made up. Running it again leaves whatever is already there.
 */
class SeedBscsEntryDemo extends Command
{
    protected $signature = 'demo:bscs
        {--email= : The Super Admin who imports and sets up (as on the screens)}
        {--trial-pin=658761 : Exam PIN of the trial examination}
        {--main-pin=275562 : Exam PIN of the main examination}
        {--main-date=2026-10-07 : Day of the main examination (open 09:00 to 12:00)}
        {--trial-hours=12 : How long the trial stays open from now}';

    protected $description = 'Set up the BSCS entry test for testing: course, the 2023 papers, a trial and the main examination with test candidates';

    private const TOPICS = [
        'ENG' => ['English', ['ENG-RC' => 'Reading Comprehension', 'ENG-GV' => 'Grammar & Vocabulary']],
        'GK' => ['General Knowledge', []],
        'PHY' => ['Physics', []], 'CHE' => ['Chemistry', []], 'MTH' => ['Mathematics', []], 'BIO' => ['Biology', []],
        'CS' => ['Computer Science', []], 'ECO' => ['Economics', []], 'STA' => ['Statistics', []],
        'DAE' => ['DAE', ['DAE-PHY' => 'DAE Physics', 'DAE-CHE' => 'DAE Chemistry', 'DAE-MTH' => 'DAE Mathematics', 'DAE-CS' => 'DAE Computer']],
    ];

    public function handle(AcademicStructure $academic, AccessControl $access): int
    {
        $user = User::query()->where('email', (string) $this->option('email'))->first();
        if ($user === null || ! $access->isSuperAdmin($user)) {
            $this->error('Give the email of a Super Admin with --email.');

            return self::FAILURE;
        }
        auth()->setUser($user);
        $branch = (int) AdminTables::query('branches')->orderBy('id')->value('id');
        $zone = (string) config('exam.timezone');

        $courseId = $this->structure($academic, $branch);
        $this->info("Course BSCS-ENTRY ready (id {$courseId}).");

        if (Question::query()->where('course_id', $courseId)->exists()) {
            $this->line('Questions already imported: left as they are.');
        } else {
            $this->importQuestions($user, $branch);
        }

        $type = (int) AdminTables::query('acad_exam_types')->where('code', 'entry')->value('id');
        $intake = (int) AdminTables::query('sessions')->where('branch_id', $branch)->where('session', 'Fall 2026')->value('id');
        $now = CarbonImmutable::now($zone)->startOfMinute();

        $trial = $this->examination($user, $branch, 'BSCS Entry Test — TRIAL (10 questions)', new ExaminationInput(
            title: 'BSCS Entry Test — TRIAL (10 questions)', courseId: $courseId, examTypeId: $type, intakeId: $intake,
            startsAt: $now->utc(), durationMinutes: 15, totalMarks: 10, passPercentage: 50, negativeMarking: false, negativeFraction: null,
            instructions: "Trial run to check the system.\nThere is one best answer to each question.",
            closesAt: $now->addHours((int) $this->option('trial-hours'))->utc(), sharedPin: (string) $this->option('trial-pin'), showResult: true,
        ), 10);
        $this->candidates($user, $trial, array_map(fn (int $i): array => [sprintf('TEST-%03d', $i), "Test Student {$i}"], range(1, 10)));

        $day = (string) $this->option('main-date');
        $main = $this->examination($user, $branch, "BSCS Entry Test 2026 — {$day}", new ExaminationInput(
            title: "BSCS Entry Test 2026 — {$day}", courseId: $courseId, examTypeId: $type, intakeId: $intake,
            startsAt: CarbonImmutable::parse("{$day} 09:00", $zone)->utc(), durationMinutes: 90, totalMarks: 100, passPercentage: 50,
            negativeMarking: false, negativeFraction: null,
            instructions: "100 questions, 90 minutes.\nThere is one best answer to each question.\nYour answers are saved as you go; the test ends by itself when the time is up.",
            closesAt: CarbonImmutable::parse("{$day} 12:00", $zone)->utc(), sharedPin: (string) $this->option('main-pin'), showResult: true,
        ), 100);
        $this->candidates($user, $main, array_map(fn (int $i): array => [sprintf('BSCS26-%04d', $i), sprintf('Test Candidate %03d', $i)], range(1, 200)));

        foreach ([$trial, $main] as $exam) {
            $exam->refresh();
            $this->info(sprintf('%s  %s  %s  PIN %s  %s – %s  candidates %d', $exam->public_ref, $exam->title, route('sit.login', $exam), $exam->shared_pin,
                $exam->starts_at?->setTimezone($zone)->format('j M H:i'), $exam->closes_at?->setTimezone($zone)->format('j M H:i'),
                DB::table('cand_candidates')->where('examination_id', $exam->id)->count()));
        }

        return self::SUCCESS;
    }

    /** BS Computer Science → Year 1 / Semester I → BSCS-ENTRY, its subjects (with codes) and the Fall 2026 intake. */
    private function structure(AcademicStructure $academic, int $branch): int
    {
        $programme = AdminTables::query('classes')->where('branch_id', $branch)->where('class', 'BS Computer Science')->value('id')
            ?? $academic->createProgramme($branch, ['name' => 'BS Computer Science', 'code' => 'BSCS', 'calendar' => 'semester', 'structure' => 'subject', 'years' => 4]);
        $year1 = (int) AdminTables::query('acad_professionals')->where('class_id', $programme)->where('sequence', 1)->value('id');
        $sem1 = (int) AdminTables::query('acad_professional_terms')->where('professional_id', $year1)->where('sequence', 1)->value('id');

        $courseId = AdminTables::query('acad_courses')->where('course_code', 'BSCS-ENTRY')->value('id')
            ?? $academic->createCourse($academic->programme($branch, (int) $programme), [
                'course_code' => 'BSCS-ENTRY', 'title' => 'BSCS Entry Test', 'professional_id' => $year1, 'term_id' => $sem1, 'credit_hours' => null,
            ], null);
        $course = $academic->course($branch, (int) $courseId);

        $nodeId = fn (string $name, ?int $parent): int => (int) AdminTables::query('acad_curriculum_nodes')
            ->where('course_id', $courseId)->where('parent_key', $parent ?? 0)->where('name', $name)->value('id');
        foreach (self::TOPICS as $code => [$name, $children]) {
            $academic->addNodes($course, null, [$name], null);
            $id = $nodeId($name, null);
            AdminTables::query('acad_curriculum_nodes')->where('id', $id)->update(['code' => $code]);
            $academic->addNodes($course, $id, array_values($children), null);
            foreach ($children as $childCode => $childName) {
                AdminTables::query('acad_curriculum_nodes')->where('id', $nodeId($childName, $id))->update(['code' => $childCode]);
            }
        }
        if (AdminTables::query('sessions')->where('branch_id', $branch)->where('session', 'Fall 2026')->doesntExist()) {
            $academic->saveIntake($branch, null, ['session' => 'Fall 2026', 'start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        }

        return (int) $courseId;
    }

    /** The 2023 papers through the question bank's own import, their pictures, then active (testing only). */
    private function importQuestions(User $user, int $branch): void
    {
        $dir = database_path('demo/bscs-2023/');
        $csv = $dir.'bscs-entry-2023-questions.csv';

        // The import attaches only tags that exist, so make the file's tags first.
        $handle = fopen($csv, 'r');
        $header = $handle === false ? false : fgetcsv($handle, 0, ',', '"', '');
        if ($handle === false || $header === false) {
            throw new \RuntimeException("Cannot read {$csv}.");
        }
        $tagColumn = array_search('tags', array_map(fn ($h) => trim((string) $h, "\u{FEFF} "), $header), true);
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            foreach (array_filter(array_map('trim', explode(',', (string) ($row[$tagColumn] ?? '')))) as $name) {
                Tag::query()->firstOrCreate(
                    ['branch_id' => $branch, 'slug' => mb_substr(Str::slug($name) ?: mb_strtolower($name), 0, 60)],
                    ['name' => $name, 'created_by' => $user->id],
                );
            }
        }
        fclose($handle);

        $import = app(CheckImport::class)($user, $branch, new UploadedFile($csv, basename($csv), 'text/csv', null, true), [])->fresh();
        $this->line("Checked: {$import->rows_valid} can be imported, {$import->rows_invalid} cannot.");
        foreach (DB::table('qb_import_rows')->where('import_id', $import->id)->whereIn('status', ['invalid', 'duplicate'])->get() as $bad) {
            $this->warn("  row {$bad->row_number}: ".$bad->errors);
        }
        app(CommitImport::class)($user, $import);

        DB::transaction(function () use ($user, $branch, $import, $dir): void {
            $rows = DB::table('qb_import_rows')->where('import_id', $import->id)->whereNotNull('version_id')->get();

            // The two Biology diagrams, while the questions are still drafts.
            $pictures = ['During the process of nerve impulse' => 'biology-q7-image1.png', 'In a given diagram graph A and B' => 'biology-q16-image2.png'];
            foreach ($rows as $row) {
                $stem = (string) (json_decode((string) $row->raw, true)['stem'] ?? '');
                foreach ($pictures as $start => $file) {
                    if (! str_starts_with($stem, $start)) {
                        continue;
                    }
                    $bytes = (string) file_get_contents($dir.'images/'.$file);
                    $path = "qbank/{$branch}/".Str::random(40).'.png';
                    Storage::disk('local')->put($path, $bytes);
                    $size = getimagesizefromstring($bytes);
                    $media = Media::query()->firstOrCreate(['branch_id' => $branch, 'checksum' => hash('sha256', $bytes)], [
                        'disk' => 'local', 'path' => $path, 'original_name' => $file, 'mime_type' => 'image/png', 'size_bytes' => strlen($bytes),
                        'width' => $size[0] ?? null, 'height' => $size[1] ?? null, 'alt_text' => 'Diagram for this question (2023 paper)', 'uploaded_by' => $user->id,
                    ]);
                    $current = (string) DB::table('qb_question_versions')->where('id', $row->version_id)->value('stem');
                    DB::table('qb_question_versions')->where('id', $row->version_id)
                        ->update(['stem' => $current.'<p><img src="/questions/media/'.$media->id.'" alt="'.e($media->alt_text).'"></p>']);
                    DB::table('qb_version_media')->insertOrIgnore(['version_id' => $row->version_id, 'media_id' => $media->id, 'role' => 'stem', 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            // Testing only: in use straight away, without the review a real question goes through.
            $versions = $rows->pluck('version_id')->all();
            foreach (['submitted', 'under_review', 'approved', 'active'] as $status) {
                DB::table('qb_question_versions')->whereIn('id', $versions)->update(['status' => $status]);
            }
            foreach ($rows as $row) {
                DB::table('qb_questions')->where('id', $row->question_id)->update(['active_version_id' => $row->version_id]);
            }
        });
        $this->info('Questions imported and made active (testing only).');
    }

    private function examination(User $user, int $branch, string $title, ExaminationInput $input, int $count): Examination
    {
        $existing = Examination::query()->where('title', $title)->first();
        if ($existing !== null) {
            $this->line("{$title}: already there.");

            return $existing;
        }

        return app(DrawWholeCoursePaper::class)->create($user, $branch, $input, $count);
    }

    /** @param list<array{0: string, 1: string}> $people candidate number (= roll number) and name */
    private function candidates(User $user, Examination $exam, array $people): void
    {
        $path = storage_path('app/private/roster-'.Str::random(20).'.csv');
        file_put_contents($path, "roll_no,name\n".implode("\n", array_map(fn (array $p): string => $p[0].','.$p[1], $people))."\n");
        $result = app(ImportCandidates::class)($user, $exam, new UploadedFile($path, 'roster.csv', 'text/csv', null, true));
        @unlink($path);
        $this->line("{$exam->title}: {$result['imported']} candidates added, {$result['skipped']} already there.");
    }
}
