<?php

namespace App\Domain\Analytics\Actions;

use App\Domain\Analytics\Queries\ItemAnalysis;
use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Actions\CandidateGuard;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Exam\Models\Examination;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionUsage;
use App\Domain\Results\Actions\CompileResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Computing and recording item statistics for an examination (exam phase, step 22) — refuses while
 * any attempt is still pending marking, the same check results already make before approval:
 * analysis on partial data would be misleading.
 */
final class RunAnalysis
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly CompileResult $compile,
        private readonly ItemAnalysis $itemAnalysis,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, Examination $examination): void
    {
        $this->guard->authorise($user, $examination, 'analytics.run', 'You cannot run analysis for this course.');

        $attempts = CandidateExam::query()->where('examination_id', $examination->id)
            ->where('status', AttemptStatus::Submitted)->with('examination')->get();

        if ($attempts->isEmpty()) {
            throw ValidationException::withMessages(['analysis' => 'Nobody has submitted this examination yet.']);
        }

        foreach ($attempts as $attempt) {
            if ($this->compile->__invoke($attempt)->pending_items) {
                throw ValidationException::withMessages(['analysis' => 'Every attempt must be fully marked before analysis can be run.']);
            }
        }

        $rows = $this->itemAnalysis->forExamination($examination);

        DB::transaction(function () use ($rows, $examination, $user): void {
            $questionIds = [];

            foreach ($rows as $row) {
                QuestionUsage::query()->updateOrCreate(
                    ['version_id' => $row['versionId'], 'exam_id' => $examination->id],
                    [
                        'question_id' => $row['questionId'],
                        'branch_id' => $examination->branch_id,
                        'exam_label' => $examination->title,
                        'used_on' => $examination->starts_at?->toDateString() ?? now()->toDateString(),
                        'candidates' => $row['candidates'],
                        'correct_count' => $row['correctCount'],
                        'observed_p' => $row['observedP'],
                        'discrimination' => $row['discrimination'],
                        'option_shares' => $row['distractors'],
                    ],
                );
                $questionIds[] = $row['questionId'];
            }

            foreach (array_unique($questionIds) as $questionId) {
                $this->refreshQuestionRollup($questionId);
            }

            $this->audit->record('analytics.run', 'examination', $examination->id, null, ['items' => count($rows)], null, $user, $examination->branch_id);
        });
    }

    private function refreshQuestionRollup(int $questionId): void
    {
        $question = Question::query()->find($questionId);
        if ($question === null) {
            return;
        }

        $usage = QuestionUsage::query()->whereIn('version_id', $question->versions()->pluck('id'))->get();

        $current = $usage->firstWhere('version_id', $question->active_version_id);

        $question->update([
            'times_used' => $usage->count(),
            'candidates_total' => $usage->sum('candidates'),
            'last_used_at' => $usage->max('used_on'),
            'last_p' => $current?->observed_p,
            'last_d' => $current?->discrimination,
        ]);
    }
}
