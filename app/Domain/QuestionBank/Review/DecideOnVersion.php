<?php

namespace App\Domain\QuestionBank\Review;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\PrehocDecision;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\VersionStatusLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The approving authority's decision on a reviewed question — one choice from KMU's list, each with
 * its own outcome (KMU requirements, "Question Quality / Decision"):
 *
 *   Accept, Retain in QBank .. approved and stored in the QBank (ApproveVersion)
 *   Review ................... reviewed again: a new round, the reviewers asked afresh
 *   Revise ................... back to its author to change it
 *   Remove / Discard ......... archived with the reason (RejectVersion)
 *
 * The approver's cognitive and difficulty level are the ones the question keeps when it is stored.
 */
final class DecideOnVersion
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly ApproveVersion $approve,
        private readonly RejectVersion $reject,
        private readonly AssignReviewers $assignments,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $approver, QuestionVersion $version, ConsolidatedPrehoc $input): QuestionVersion
    {
        $decision = PrehocDecision::query()->where('is_active', true)->find($input->decisionId);
        if (! $decision instanceof PrehocDecision) {
            throw ValidationException::withMessages(['decision_id' => 'Choose the decision for this question.']);
        }

        return match ($decision->code) {
            'accept', 'retain' => ($this->approve)($approver, $version, $input),
            'remove' => ($this->reject)($approver, $version, (string) $input->reason),
            'revise' => $this->backToAuthor($approver, $version, (string) $input->reason),
            'review' => $this->reviewAgain($approver, $version, (string) $input->reason),
            default => throw ValidationException::withMessages(['decision_id' => 'That decision cannot be taken here.']),
        };
    }

    /** "Revise": the question goes back to its author, with what to change. */
    private function backToAuthor(User $approver, QuestionVersion $version, string $reason): QuestionVersion
    {
        $this->authorise($approver, $version);
        $reason = $this->reasonOrFail($reason, 'Say what the author has to change (at least 10 characters).');

        return DB::transaction(function () use ($approver, $version, $reason): QuestionVersion {
            $this->assignments->cancelOpenFor($version, $approver, 'The question went back to its author for changes.');
            $this->move($version, VersionStatus::ChangesRequested, $approver, $reason);
            $version->update(['decision_code' => 'revise']);

            $this->audit->record('qbank.question.returned', 'question_version', $version->id, ['status' => VersionStatus::UnderReview->value], [
                'status' => VersionStatus::ChangesRequested->value,
                'decision' => 'revise',
            ], $reason, $approver, $version->branch_id);

            return $version->fresh() ?? $version;
        });
    }

    /** "Review": another round of review, with the department / subject reviewers asked afresh. */
    private function reviewAgain(User $approver, QuestionVersion $version, string $reason): QuestionVersion
    {
        $this->authorise($approver, $version);
        $reason = $this->reasonOrFail($reason, 'Say what the reviewers should look at again (at least 10 characters).');

        return DB::transaction(function () use ($approver, $version, $reason): QuestionVersion {
            $this->assignments->cancelOpenFor($version, $approver, 'The question is being reviewed again.');
            $version->update([
                'review_round' => $version->review_round + 1,
                'decision_code' => 'review',
                'updated_by' => $approver->id,
            ]);

            $fresh = $version->fresh() ?? $version;
            $this->assignments->auto($fresh, $approver, ReviewStage::Subject);

            $this->audit->record('qbank.question.review_again', 'question_version', $version->id, null, [
                'decision' => 'review',
                'round' => $fresh->review_round,
            ], $reason, $approver, $version->branch_id);

            return $fresh;
        });
    }

    private function authorise(User $approver, QuestionVersion $version): void
    {
        $target = new ScopeTarget($version->branch_id, $version->programme_id, $version->professional_id, $version->course_id);
        if (! $this->access->allows($approver, 'qbank.question.approve', $target)) {
            throw new AuthorizationException('You cannot decide about questions of this course.');
        }
        if ($version->author_id === $approver->id) {
            throw new AuthorizationException('You cannot decide about your own question.');
        }
        if ($version->status !== VersionStatus::UnderReview) {
            throw ValidationException::withMessages(['status' => 'Only a question that has been reviewed can be decided on.']);
        }
    }

    private function reasonOrFail(string $reason, string $message): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => $message]);
        }

        return $reason;
    }

    private function move(QuestionVersion $version, VersionStatus $to, User $actor, ?string $reason): void
    {
        $from = $version->status;
        $version->update(['status' => $to, 'updated_by' => $actor->id]);

        VersionStatusLog::query()->create([
            'version_id' => $version->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
