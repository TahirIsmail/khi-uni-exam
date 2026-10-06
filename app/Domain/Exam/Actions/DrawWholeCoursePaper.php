<?php

namespace App\Domain\Exam\Actions;

use App\Domain\Blueprint\Actions\BlueprintWorkflow;
use App\Domain\Blueprint\Actions\SaveBlueprint;
use App\Domain\Blueprint\BlueprintInput;
use App\Domain\Exam\ExaminationInput;
use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Paper\Actions\CreatePaper;
use App\Domain\Paper\Actions\FillPaper;
use App\Domain\Paper\Actions\PaperWorkflow;
use App\Domain\Paper\Actions\UpdatePaperSettings;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\Paper\Queries\PaperData;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An entry test in one step: "this many questions from the course". The examination's blueprint is
 * one row — that many single-best-answer questions from anywhere in the course, the total marks
 * shared equally — and its paper is drawn from questions no other examination has used, with the
 * questions and their options shuffled for every candidate, then moderated and published.
 *
 * It goes through the usual steps (blueprint, paper, moderation, publication), each recorded in the
 * audit log; only the rule that somebody else approves is waived, which is why it is the Super
 * Admin's alone. If the course cannot supply enough unused questions nothing is kept.
 */
final class DrawWholeCoursePaper
{
    /** How often a clash is drawn around before giving up. */
    private const ROUNDS = 10;

    public function __construct(
        private readonly AccessControl $access,
        private readonly SaveBlueprint $saveBlueprint,
        private readonly BlueprintWorkflow $blueprints,
        private readonly CreatePaper $createPaper,
        private readonly UpdatePaperSettings $paperSettings,
        private readonly FillPaper $fill,
        private readonly PaperWorkflow $papers,
        private readonly CreateExamination $create,
        private readonly PaperData $report,
    ) {}

    /**
     * The questions to put back: of each pair that clashes, the one whose answer is given away, and
     * every repeat of a text after its first.
     *
     * @return list<string> question references
     */
    private function clashing(Examination $examination, Paper $paper): array
    {
        $refs = [];
        foreach ($this->report->report($examination, $paper->refresh())['blockers'] as $blocker) {
            if (preg_match_all('/gives away ([A-Z]+-\d{4}-\d+)/', $blocker, $m) > 0) {
                $refs = [...$refs, ...$m[1]];
            } elseif (str_starts_with($blocker, 'The same question text') && preg_match_all('/[A-Z]+-\d{4}-\d+/', $blocker, $m) > 1) {
                $refs = [...$refs, ...array_slice($m[0], 1)];
            }
        }

        return array_values(array_unique($refs));
    }

    /** A new examination together with its published paper, or nothing at all. */
    public function create(User $user, int $branchId, ExaminationInput $input, int $count): Examination
    {
        return DB::transaction(function () use ($user, $branchId, $input, $count): Examination {
            $examination = ($this->create)($user, $branchId, $input);
            $this($user, $examination, $count);

            return $examination->refresh();
        });
    }

    public function __invoke(User $user, Examination $examination, int $count): Paper
    {
        if (! $this->access->isSuperAdmin($user)) {
            throw new AuthorizationException('Only a Super Admin can set up an examination and its paper in one step.');
        }

        $marksEach = round((float) $examination->total_marks / $count, 2);
        if (abs($marksEach * $count - (float) $examination->total_marks) > 0.001) {
            throw ValidationException::withMessages(['question_count' => "The total marks ({$examination->total_marks}) cannot be shared equally among {$count} questions."]);
        }
        $typeId = (int) QuestionType::query()->where('code', 'single_best_answer')->value('id');

        return DB::transaction(function () use ($user, $examination, $count, $marksEach, $typeId): Paper {
            ($this->saveBlueprint)($user, $examination, new BlueprintInput(
                sections: [],
                rows: [['section' => null, 'node_id' => 0, 'question_type_id' => $typeId, 'question_count' => $count, 'marks_each' => $marksEach]],
                cognitive: [],
                difficulty: [],
            ));
            $this->blueprints->submit($user, $examination->refresh());
            $this->blueprints->approve($user, $examination->refresh(), selfApproved: true);

            $paper = ($this->createPaper)($user, $examination->refresh());
            ($this->paperSettings)($user, $examination, $paper, shuffleQuestions: true, shuffleOptions: true);
            // Drawn, checked, and drawn again where two questions clash (one gives away the other's
            // answer, or the same text twice): the second of each pair is put back and avoided.
            $avoid = [];
            for ($round = 1; ; $round++) {
                $drawn = ($this->fill)($user, $examination, $paper->refresh(), 'gaps', $avoid);
                if ($drawn['missing'] > 0) {
                    $found = $count - $drawn['missing'];
                    throw ValidationException::withMessages(['question_count' => $avoid === []
                        ? "The course has only {$found} questions in use that no other examination has used; {$count} are needed. Add questions to the bank, or ask for fewer."
                        : "Only {$found} of the {$count} questions could be drawn without two of them giving each other away. Add questions to the bank, or ask for fewer."]);
                }
                $clashing = $this->clashing($examination, $paper);
                if ($clashing === []) {
                    break;
                }
                if ($round === self::ROUNDS) {
                    throw ValidationException::withMessages(['question_count' => 'The questions keep giving each other away: '.implode(', ', $clashing).'. Draw the paper step by step instead.']);
                }
                $ids = Question::query()->where('branch_id', $examination->branch_id)->whereIn('public_ref', $clashing)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
                PaperItem::query()->where('paper_id', $paper->id)->whereIn('question_id', $ids)->delete();
                $avoid = array_values(array_unique([...$avoid, ...$ids]));
            }

            $paper = $this->papers->submit($user, $examination, $paper->refresh());
            $paper = $this->papers->approve($user, $examination, $paper, selfApproved: true);
            $paper = $this->papers->finalise($user, $examination, $paper);

            return $this->papers->publish($user, $examination->refresh(), $paper);
        });
    }
}
