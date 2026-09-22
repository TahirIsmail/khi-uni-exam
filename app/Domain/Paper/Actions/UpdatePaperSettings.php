<?php

namespace App\Domain\Paper\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\Models\Paper;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * How each candidate meets the paper: the questions in an order of their own or in one order for
 * everybody, and the options of a question likewise.
 */
final class UpdatePaperSettings
{
    public function __construct(
        private readonly PaperGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, Examination $examination, Paper $paper, bool $shuffleQuestions, bool $shuffleOptions): Paper
    {
        $this->guard->authorise($user, $examination);
        $this->guard->mustBeEditable($examination, $paper);

        return DB::transaction(function () use ($user, $examination, $paper, $shuffleQuestions, $shuffleOptions): Paper {
            $before = ['shuffle_questions' => $paper->shuffle_questions, 'shuffle_options' => $paper->shuffle_options];
            $paper->update(['shuffle_questions' => $shuffleQuestions, 'shuffle_options' => $shuffleOptions, 'updated_by' => $user->id]);

            $after = ['shuffle_questions' => $shuffleQuestions, 'shuffle_options' => $shuffleOptions];
            if ($before !== $after) {
                $this->audit->record('paper.settings_changed', 'paper', $paper->id, $before, $after, null, $user, $examination->branch_id);
            }

            return $paper;
        });
    }
}
