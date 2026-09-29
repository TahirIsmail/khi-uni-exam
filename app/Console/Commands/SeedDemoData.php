<?php

namespace App\Console\Commands;

use App\Domain\Analytics\Actions\RunAnalysis;
use App\Domain\Blueprint\Actions\BlueprintWorkflow;
use App\Domain\Blueprint\Actions\SaveBlueprint;
use App\Domain\Blueprint\BlueprintInput;
use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Delivery\Actions\RecordAnswer;
use App\Domain\Delivery\Actions\StartOrResumeAttempt;
use App\Domain\Delivery\Actions\SubmitAttempt;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Exam\Actions\CreateExamination;
use App\Domain\Exam\ExaminationInput;
use App\Domain\Exam\Models\Examination;
use App\Domain\Marking\Actions\AssignExaminer;
use App\Domain\Marking\Actions\AutoMarkAttempt;
use App\Domain\Marking\Actions\FinaliseItemMark;
use App\Domain\Marking\Actions\RecordExaminerMark;
use App\Domain\Marking\Enums\ExaminerRole;
use App\Domain\Paper\Actions\CreatePaper;
use App\Domain\Paper\Actions\FillPaper;
use App\Domain\Paper\Actions\PaperWorkflow;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\QuestionBank\PublicRef;
use App\Domain\Results\Actions\ApproveResults;
use App\Domain\Results\Actions\CompileResult;
use App\Domain\Results\Actions\PublishResults;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Builds the data a client demo needs: a stocked question bank and an examination whose paper is
 * published, so the demo can go straight to checking a candidate in and sitting the exam.
 *
 * Local demo use only — it empties the exam module's tables first.
 */
class SeedDemoData extends Command
{
    protected $signature = 'demo:seed {--keep : Add to what is there instead of emptying it first}';

    protected $description = 'Empty the exam module and build demo data: a stocked question bank and a published examination';

    /** MBBS-1-FND Foundation Module curriculum nodes that take questions. */
    private const NODE_ANATOMY_TERMS = 86;

    private const NODE_ANATOMY_CELLS = 87;

    private const NODE_PHYSIOLOGY_HOMEOSTASIS = 90;

    private const NODE_BIOCHEM_CELL = 94;

    private const TYPE_SBA = 1;

    private const TYPE_SHORT_ANSWER = 8;

    private const TYPE_ESSAY = 11;

    private const COURSE_ID = 6;

    private const EXAM_TYPE_ANNUAL = 1;

    private const INTAKE_ID = 34;

    private const BRANCH_ID = 1;

    public function handle(): int
    {
        $author = User::query()->where('email', 'author@demo.com')->first();
        $approver = User::query()->where('email', 'approver@demo.com')->first();

        if ($author === null || $approver === null) {
            $this->error('Needs author@demo.com and approver@demo.com to have signed in once through the CMS.');

            return self::FAILURE;
        }

        if (! $this->option('keep')) {
            $this->emptyExamModule();
        }

        $this->components->info('Filling the question bank.');
        $questions = $this->seedQuestions($author);
        $this->components->twoColumnDetail('Questions written and approved', (string) count($questions));

        $this->components->info('Building the examination to sit in the demo.');
        $exam = $this->seedExamination($author, $approver, 'MBBS First Professional Annual Examination 2026 — Foundation Module', CarbonImmutable::now()->addHours(2));

        $this->components->info('Building last session\'s examination, already marked and published.');
        $finished = $this->seedExamination($author, $approver, 'MBBS First Professional Annual Examination 2025 — Foundation Module', CarbonImmutable::now()->subMonths(6));
        $this->sitMarkAndPublish($finished, $approver);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>To sit in the demo</>', $exam->title);
        $this->components->twoColumnDetail('  Paper', 'published — 12 candidates allocated, none checked in yet');
        $this->components->twoColumnDetail('  In the demo', 'check a candidate in for their PIN, then sit at /sit/'.$exam->id);
        $this->components->twoColumnDetail('<fg=green>Already finished</>', $finished->title);
        $this->components->twoColumnDetail('  Results', 'marked, approved and published');
        $this->components->twoColumnDetail('  Item analysis', '/results/'.$finished->id.'/analysis');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * TRUNCATE rather than DELETE: every one of these tables carries an append-only or frozen-row
     * trigger that refuses deletes, which is the point of them — TRUNCATE does not fire triggers.
     */
    private function emptyExamModule(): void
    {
        $tables = [
            'qb_question_usage', 'qb_posthoc_decisions', 'qb_prehoc_assessments', 'qb_prehoc_decisions',
            'qb_reviews', 'qb_review_assignments', 'qb_review_checklist_items', 'qb_version_status_log',
            'qb_version_tags', 'qb_version_media', 'qb_references', 'qb_question_rubric_criteria',
            'qb_question_options', 'qb_question_items', 'qb_question_answers', 'qb_question_versions',
            'qb_questions', 'qb_import_rows', 'qb_imports', 'qb_media',
            'mrk_item_mark_criteria', 'mrk_item_marks', 'mrk_examiner_assignments',
            'dlv_answer_events', 'dlv_answers_current', 'dlv_submissions', 'dlv_sessions',
            'dlv_proctor_decisions', 'dlv_proctor_events',
            'cand_paper_items', 'cand_candidate_exams', 'cand_candidates', 'cand_devices',
            'cand_rooms', 'cand_centres',
            'exm_component_marks', 'exm_result_components',
            'exm_result_publications', 'exm_results', 'exm_item_rekeys',
            'exm_paper_comments', 'exm_paper_items', 'exm_papers',
            'exm_blueprint_targets', 'exm_blueprint_rows', 'exm_blueprints',
            'exm_sections', 'exm_examinations',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0'); // raw-sql-reviewed: fixed statement, no user input
        foreach ($tables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::statement("TRUNCATE TABLE `{$table}`"); // raw-sql-reviewed: names come from the list above, never from input
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1'); // raw-sql-reviewed: fixed statement, no user input

        $this->components->info('Emptied the exam module (question bank, examinations, candidates, results).');
    }

    /**
     * Written straight into the bank as approved and in use. The review journey is demonstrated
     * live on a question written during the demo, so the bank only needs to be stocked here.
     *
     * @return list<int> version ids
     */
    private function seedQuestions(User $author): array
    {
        $versions = [];

        foreach ($this->questionContent() as $content) {
            $versions[] = $this->writeQuestion($author, $content);
        }

        return $versions;
    }

    /**
     * @param  array{node: int, type: int, cognitive: int, difficulty: int, stem: string, options?: list<array{0: string, 1: string, 2: bool}>, rubric?: list<array{0: string, 1: float}>}  $content
     */
    private function writeQuestion(User $author, array $content): int
    {
        return DB::transaction(fn (): int => $this->writeQuestionRow($author, $content));
    }

    /**
     * @param  array{node: int, type: int, cognitive: int, difficulty: int, stem: string, options?: list<array{0: string, 1: string, 2: bool}>, rubric?: list<array{0: string, 1: float}>, accepted?: list<string>}  $content
     */
    private function writeQuestionRow(User $author, array $content): int
    {
        $now = now();
        $text = strip_tags($content['stem']);

        $questionId = DB::table('qb_questions')->insertGetId([
            'public_ref' => PublicRef::next(),
            'branch_id' => self::BRANCH_ID,
            'course_id' => self::COURSE_ID,
            'latest_version_no' => 1,
            'is_archived' => false,
            'times_used' => 0,
            'created_by' => $author->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Options may only be written while the version is a draft, so it starts there and the
        // status walks forward once its parts are in place — the same order the screens use.
        $versionId = DB::table('qb_question_versions')->insertGetId([
            'question_id' => $questionId,
            'version_no' => 1,
            'question_type_id' => $content['type'],
            'branch_id' => self::BRANCH_ID,
            'course_id' => self::COURSE_ID,
            'node_id' => $content['node'],
            'exam_type_id' => self::EXAM_TYPE_ANNUAL,
            'cognitive_level_id' => $content['cognitive'],
            'difficulty_level_id' => $content['difficulty'],
            'stem' => $content['stem'],
            'content_hash' => hash('sha256', $text),
            'search_text' => $text,
            'status' => 'draft',
            'author_id' => $author->id,
            'created_by' => $author->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($content['options'] ?? [] as $index => [$label, $body, $correct]) {
            DB::table('qb_question_options')->insert([
                'version_id' => $versionId,
                'label' => $label,
                'body' => $body,
                'is_correct' => $correct,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($content['rubric'] ?? [] as $index => [$criterion, $marks]) {
            DB::table('qb_question_rubric_criteria')->insert([
                'version_id' => $versionId,
                'criterion' => $criterion,
                'max_marks' => $marks,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // What a short answer will accept. The computer matches against these and suggests a mark;
        // an examiner still has to agree with it before it counts.
        foreach ($content['accepted'] ?? [] as $index => $answer) {
            DB::table('qb_question_answers')->insert([
                'version_id' => $versionId,
                'match_mode' => 'exact',
                'answer_text' => $answer,
                'case_sensitive' => false,
                'marks_fraction' => 1,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (['submitted', 'under_review', 'approved', 'active'] as $status) {
            DB::table('qb_question_versions')->where('id', $versionId)->update(['status' => $status]);
        }

        DB::table('qb_questions')->where('id', $questionId)->update(['active_version_id' => $versionId]);

        return $versionId;
    }

    private function seedExamination(User $author, User $approver, string $title, CarbonImmutable $startsAt): Examination
    {
        $exam = app(CreateExamination::class)($author, self::BRANCH_ID, new ExaminationInput(
            title: $title,
            courseId: self::COURSE_ID,
            examTypeId: self::EXAM_TYPE_ANNUAL,
            intakeId: self::INTAKE_ID,
            startsAt: $startsAt,
            durationMinutes: 60,
            totalMarks: 50,
            passPercentage: 50,
            negativeMarking: false,
            negativeFraction: null,
            instructions: 'Answer every question. The paper is marked out of 50 and the pass mark is 50%.',
        ));

        app(SaveBlueprint::class)($author, $exam, new BlueprintInput(
            sections: [],
            rows: [
                ['section' => null, 'node_id' => self::NODE_ANATOMY_TERMS, 'question_type_id' => self::TYPE_SBA, 'question_count' => 3, 'marks_each' => 5.0],
                ['section' => null, 'node_id' => self::NODE_PHYSIOLOGY_HOMEOSTASIS, 'question_type_id' => self::TYPE_SBA, 'question_count' => 3, 'marks_each' => 5.0],
                ['section' => null, 'node_id' => self::NODE_BIOCHEM_CELL, 'question_type_id' => self::TYPE_SBA, 'question_count' => 1, 'marks_each' => 5.0],
                // One of each kind a person has to look at: a short answer the computer only
                // suggests a mark for, and an essay nothing can mark but an examiner.
                ['section' => null, 'node_id' => self::NODE_BIOCHEM_CELL, 'question_type_id' => self::TYPE_SHORT_ANSWER, 'question_count' => 1, 'marks_each' => 5.0],
                ['section' => null, 'node_id' => self::NODE_ANATOMY_CELLS, 'question_type_id' => self::TYPE_ESSAY, 'question_count' => 1, 'marks_each' => 10.0],
            ],
            cognitive: [
                ['level_id' => 1, 'percent' => 40.0],
                ['level_id' => 2, 'percent' => 40.0],
                ['level_id' => 3, 'percent' => 20.0],
            ],
            difficulty: [
                ['level_id' => 1, 'percent' => 30.0],
                ['level_id' => 2, 'percent' => 50.0],
                ['level_id' => 3, 'percent' => 20.0],
            ],
        ));

        $workflow = app(BlueprintWorkflow::class);
        $workflow->submit($author, $exam);
        $workflow->approve($approver, $exam);
        $exam->refresh();

        $paper = app(CreatePaper::class)($author, $exam);
        app(FillPaper::class)($author, $exam, $paper, 'gaps');

        $papers = app(PaperWorkflow::class);
        $paper->refresh();
        $paper = $papers->submit($author, $exam, $paper);
        $paper = $papers->approve($approver, $exam, $paper);
        $paper = $papers->finalise($approver, $exam, $paper);
        $papers->publish($approver, $exam, $paper);

        $this->seedCandidates($exam, $approver);

        return $exam->refresh();
    }

    /**
     * Sits the whole roster, marks it and publishes the results, so the results and item-analysis
     * screens have a real examination behind them. Candidates are given a deliberate spread of
     * ability: the analysis is only interesting when the strong and weak groups differ.
     */
    private function sitMarkAndPublish(Examination $exam, User $staff): void
    {
        $checkIn = app(CheckInCandidate::class);
        $start = app(StartOrResumeAttempt::class);
        $record = app(RecordAnswer::class);
        $submit = app(SubmitAttempt::class);
        $autoMark = app(AutoMarkAttempt::class);
        $examinerMark = app(RecordExaminerMark::class);
        $finaliseMark = app(FinaliseItemMark::class);
        $compile = app(CompileResult::class);

        // Marking is refused until somebody is actually assigned as an examiner for the paper.
        app(AssignExaminer::class)($staff, $exam, $staff, ExaminerRole::First);

        $candidates = Candidate::query()->where('examination_id', $exam->id)->orderBy('candidate_no')->get();
        $bar = $this->output->createProgressBar($candidates->count());
        $bar->start();

        foreach ($candidates->values() as $rank => $candidate) {
            // 0.0 for the weakest candidate through to 1.0 for the strongest.
            $ability = $candidates->count() > 1 ? 1 - ($rank / ($candidates->count() - 1)) : 1.0;

            $pin = $checkIn($staff, $exam, $candidate)['pin'];
            $started = $start($exam, $candidate->candidate_no, $pin, '127.0.0.1', 'Demo seeder');
            $attempt = $started['attempt'];
            $session = $started['session'];

            $items = $attempt->items()->with('paperItem')->get();
            $sequence = 0;

            foreach ($items->values() as $position => $item) {
                // Later items ask more of a candidate: the bar rises across the paper, and a
                // candidate answers correctly whenever their ability clears it.
                $demands = $items->count() > 1 ? ($position / ($items->count() - 1)) * 0.9 : 0.0;
                $payload = $this->answerFor($item->paperItem, $ability, $demands, $position);
                $record($attempt, $session, $item, ++$sequence, $payload, false, null);
            }

            $submit($attempt, 'candidate');
            $attempt->refresh();
            $autoMark($attempt);

            $essayItems = $attempt->items()
                ->with(['paperItem', 'candidateExam.examination'])
                ->get()
                ->filter(fn ($item): bool => (int) $item->paperItem->question_type_id === self::TYPE_ESSAY);

            $this->confirmShortAnswers($attempt, $staff, $examinerMark, $finaliseMark);

            foreach ($essayItems as $item) {

                $criteria = DB::table('qb_question_rubric_criteria')
                    ->where('version_id', $item->paperItem->version_id)
                    ->orderBy('sort_order')
                    ->get();

                $awarded = 0.0;
                $breakdown = [];
                foreach ($criteria as $criterion) {
                    $marks = round((float) $criterion->max_marks * (0.4 + 0.55 * $ability), 1);
                    $awarded += $marks;
                    $breakdown[] = ['rubric_criterion_id' => (int) $criterion->id, 'marks_awarded' => $marks];
                }

                // The action refuses a total that does not equal the criteria added up.
                $examinerMark($staff, $item, round($awarded, 1), $breakdown, 'Marked against the rubric.');

                $finaliseMark(CandidatePaperItem::query()
                    ->with(['paperItem', 'candidateExam.examination'])
                    ->findOrFail($item->id));
            }

            $compile($attempt->refresh());
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        app(ApproveResults::class)($staff, $exam);
        app(PublishResults::class)($staff, $exam);
        app(RunAnalysis::class)($staff, $exam);
    }

    /**
     * The examiner agreeing with the computer about a short answer.
     *
     * A typed answer is only ever a suggestion, so the finished examination's results would sit
     * pending for ever if nobody confirmed them. The seeder confirms them as they stand — which is
     * what an examiner would do for the ones the computer got right, and leaves the demo free to
     * show the disagreement case live on the examination still to be sat.
     */
    private function confirmShortAnswers(
        CandidateExam $attempt,
        User $staff,
        RecordExaminerMark $examinerMark,
        FinaliseItemMark $finaliseMark,
    ): void {
        $items = $attempt->items()->with(['paperItem', 'candidateExam.examination'])->get()
            ->filter(fn ($item): bool => (int) $item->paperItem->question_type_id === self::TYPE_SHORT_ANSWER);

        foreach ($items as $item) {
            $suggested = (float) DB::table('mrk_item_marks')
                ->where('cand_paper_item_id', $item->id)->where('source', 'auto')->value('marks_awarded');

            $examinerMark($staff, $item, $suggested, [], 'Confirmed the computer\'s reading of the typed answer.');

            $finaliseMark(CandidatePaperItem::query()
                ->with(['paperItem', 'candidateExam.examination'])
                ->findOrFail($item->id));
        }
    }

    /**
     * A stronger candidate answers more of the objective items correctly and writes a fuller essay.
     *
     * @return array<string, mixed>
     */
    private function answerFor(PaperItem $paperItem, float $ability, float $bar, int $position): array
    {
        if ((int) $paperItem->question_type_id === self::TYPE_SHORT_ANSWER) {
            // A weaker candidate writes something that will not match the accepted list — which is
            // exactly the case an examiner has to look at rather than trust the computer on.
            $accepted = (string) DB::table('qb_question_answers')
                ->where('version_id', $paperItem->version_id)->orderBy('sort_order')->value('answer_text');

            return ['text' => $ability > $bar ? $accepted : 'the cell body'];
        }

        if ((int) $paperItem->question_type_id === self::TYPE_ESSAY) {
            return ['text' => $ability > 0.6
                ? 'The plasma membrane is a phospholipid bilayer whose hydrophilic heads face the aqueous compartments and whose hydrophobic tails face inwards. Integral proteins span it and act as channels, carriers and pumps, so small non-polar molecules cross freely while ions and polar solutes cross only where a protein allows them. That is what makes the membrane selectively permeable.'
                : 'The plasma membrane is made of lipids and proteins. It controls what goes in and out of the cell.'];
        }

        $options = DB::table('qb_question_options')
            ->where('version_id', $paperItem->version_id)
            ->orderBy('sort_order')
            ->get(['id', 'is_correct']);

        if ($options->isEmpty()) {
            return [];
        }

        $correct = $options->firstWhere('is_correct', 1);
        $wrong = $options->where('is_correct', 0)->values();

        // Deterministic, so re-seeding gives the same picture rather than a different one each time.
        // `selected` is a list even for a single best answer, which is what the scorer expects.
        if ($correct !== null && $ability >= $bar) {
            return ['selected' => [(int) $correct->id]];
        }

        $pick = $wrong->get($position % max($wrong->count(), 1));

        return ['selected' => [(int) ($pick->id ?? $correct->id)]];
    }

    private function seedCandidates(Examination $exam, User $officer): void
    {
        $now = now();

        // A centre and its rooms are campus infrastructure, shared by every examination held there.
        $centreId = (int) (DB::table('cand_centres')->where('branch_id', self::BRANCH_ID)->where('code', 'MEH')->value('id')
            ?? DB::table('cand_centres')->insertGetId([
                'branch_id' => self::BRANCH_ID,
                'name' => 'Main Examination Hall',
                'code' => 'MEH',
                'is_active' => true,
                'created_by' => $officer->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

        $roomId = (int) (DB::table('cand_rooms')->where('centre_id', $centreId)->where('name', 'Hall A')->value('id')
            ?? DB::table('cand_rooms')->insertGetId([
                'centre_id' => $centreId,
                'name' => 'Hall A',
                'capacity' => 40,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

        foreach ($this->candidateRoster() as $seat => [$number, $name, $roll]) {
            DB::table('cand_candidates')->insert([
                'examination_id' => $exam->id,
                'branch_id' => self::BRANCH_ID,
                'candidate_no' => $number,
                'name' => $name,
                'roll_no' => $roll,
                'status' => 'allocated',
                'centre_id' => $centreId,
                'room_id' => $roomId,
                'seat_no' => (string) ($seat + 1),
                'allocated_by' => $officer->id,
                'allocated_at' => $now,
                'created_by' => $officer->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function candidateRoster(): array
    {
        return [
            ['MBBS-26-001', 'Ayesha Khan', '2026-MB-001'],
            ['MBBS-26-002', 'Bilal Ahmed', '2026-MB-002'],
            ['MBBS-26-003', 'Fatima Noor', '2026-MB-003'],
            ['MBBS-26-004', 'Hassan Raza', '2026-MB-004'],
            ['MBBS-26-005', 'Iqra Siddiqui', '2026-MB-005'],
            ['MBBS-26-006', 'Junaid Aslam', '2026-MB-006'],
            ['MBBS-26-007', 'Komal Shah', '2026-MB-007'],
            ['MBBS-26-008', 'Mudassir Ali', '2026-MB-008'],
            ['MBBS-26-009', 'Nimra Javed', '2026-MB-009'],
            ['MBBS-26-010', 'Osama Tariq', '2026-MB-010'],
            ['MBBS-26-011', 'Rabia Sultan', '2026-MB-011'],
            ['MBBS-26-012', 'Saad Mehmood', '2026-MB-012'],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: bool}>  $options
     * @return array{node: int, type: int, cognitive: int, difficulty: int, stem: string, options: list<array{0: string, 1: string, 2: bool}>}
     */
    private function singleBestAnswer(int $node, int $cognitive, int $difficulty, string $stem, array $options): array
    {
        return [
            'node' => $node,
            'type' => self::TYPE_SBA,
            'cognitive' => $cognitive,
            'difficulty' => $difficulty,
            'stem' => '<p>'.$stem.'</p>',
            'options' => $options,
        ];
    }

    /**
     * @return list<array{node: int, type: int, cognitive: int, difficulty: int, stem: string, options?: list<array{0: string, 1: string, 2: bool}>, rubric?: list<array{0: string, 1: float}>}>
     */
    private function questionContent(): array
    {
        $sba = $this->singleBestAnswer(...);

        return [
            $sba(self::NODE_ANATOMY_TERMS, 1, 1, 'A structure lying nearer to the median plane than another is described as:', [
                ['A', 'Medial', true], ['B', 'Lateral', false], ['C', 'Superficial', false], ['D', 'Distal', false],
            ]),
            $sba(self::NODE_ANATOMY_TERMS, 1, 1, 'Movement of a limb away from the midline of the body in the coronal plane is called:', [
                ['A', 'Abduction', true], ['B', 'Adduction', false], ['C', 'Flexion', false], ['D', 'Extension', false],
            ]),
            $sba(self::NODE_ANATOMY_TERMS, 2, 2, 'In the anatomical position, the palms of the hands face:', [
                ['A', 'Anteriorly', true], ['B', 'Posteriorly', false], ['C', 'Medially', false], ['D', 'Laterally', false],
            ]),
            $sba(self::NODE_ANATOMY_TERMS, 2, 2, 'The plane dividing the body into equal right and left halves is the:', [
                ['A', 'Median (midsagittal) plane', true], ['B', 'Coronal plane', false], ['C', 'Transverse plane', false], ['D', 'Oblique plane', false],
            ]),
            $sba(self::NODE_PHYSIOLOGY_HOMEOSTASIS, 1, 1, 'The largest fluid compartment of the human body is the:', [
                ['A', 'Intracellular fluid', true], ['B', 'Interstitial fluid', false], ['C', 'Plasma', false], ['D', 'Transcellular fluid', false],
            ]),
            $sba(self::NODE_PHYSIOLOGY_HOMEOSTASIS, 1, 2, 'Total body water in a normal adult male is approximately what percentage of body weight?', [
                ['A', '60%', true], ['B', '20%', false], ['C', '40%', false], ['D', '80%', false],
            ]),
            $sba(self::NODE_PHYSIOLOGY_HOMEOSTASIS, 2, 2, 'A negative feedback mechanism in homeostasis characteristically:', [
                ['A', 'Opposes the initial change', true], ['B', 'Amplifies the initial change', false], ['C', 'Has no effect on the variable', false], ['D', 'Operates only in disease', false],
            ]),
            $sba(self::NODE_PHYSIOLOGY_HOMEOSTASIS, 3, 3, 'Normal plasma osmolality in a healthy adult is approximately:', [
                ['A', '290 mOsm/kg', true], ['B', '190 mOsm/kg', false], ['C', '390 mOsm/kg', false], ['D', '490 mOsm/kg', false],
            ]),
            $sba(self::NODE_BIOCHEM_CELL, 1, 1, 'The peptide bond of a protein is formed between:', [
                ['A', 'The carboxyl group of one amino acid and the amino group of the next', true],
                ['B', 'Two carboxyl groups', false], ['C', 'Two amino groups', false], ['D', 'Two side chains', false],
            ]),
            $sba(self::NODE_BIOCHEM_CELL, 1, 1, 'The principal site of ATP production in the cell is the:', [
                ['A', 'Mitochondrion', true], ['B', 'Ribosome', false], ['C', 'Golgi apparatus', false], ['D', 'Lysosome', false],
            ]),
            $sba(self::NODE_BIOCHEM_CELL, 2, 2, 'The main storage form of carbohydrate in the human liver is:', [
                ['A', 'Glycogen', true], ['B', 'Starch', false], ['C', 'Cellulose', false], ['D', 'Free glucose', false],
            ]),
            $sba(self::NODE_BIOCHEM_CELL, 2, 3, 'Which of the following is a purine base?', [
                ['A', 'Adenine', true], ['B', 'Cytosine', false], ['C', 'Thymine', false], ['D', 'Uracil', false],
            ]),
            [
                'node' => self::NODE_BIOCHEM_CELL, 'type' => self::TYPE_SHORT_ANSWER, 'cognitive' => 1, 'difficulty' => 1,
                'stem' => '<p>Name the organelle in which oxidative phosphorylation takes place.</p>',
                'accepted' => ['mitochondrion', 'mitochondria'],
            ],
            [
                'node' => self::NODE_BIOCHEM_CELL, 'type' => self::TYPE_SHORT_ANSWER, 'cognitive' => 1, 'difficulty' => 2,
                'stem' => '<p>Name the storage polysaccharide of the human liver.</p>',
                'accepted' => ['glycogen'],
            ],
            [
                'node' => self::NODE_ANATOMY_CELLS, 'type' => self::TYPE_ESSAY, 'cognitive' => 2, 'difficulty' => 2,
                'stem' => '<p>Describe the structure of the plasma membrane and explain how its organisation supports selective permeability.</p>',
                'rubric' => [
                    ['Describes the lipid bilayer and its amphipathic arrangement', 4.0],
                    ['Describes membrane proteins and their transport roles', 3.0],
                    ['Relates the structure to selective permeability with an example', 3.0],
                ],
            ],
            [
                'node' => self::NODE_ANATOMY_CELLS, 'type' => self::TYPE_ESSAY, 'cognitive' => 3, 'difficulty' => 3,
                'stem' => '<p>Classify epithelial tissue by cell shape and layering, giving one example of each type and its functional significance.</p>',
                'rubric' => [
                    ['Classifies by layering (simple, stratified, pseudostratified)', 4.0],
                    ['Classifies by cell shape (squamous, cuboidal, columnar)', 3.0],
                    ['Gives a correct example and its function for each', 3.0],
                ],
            ],
        ];
    }
}
