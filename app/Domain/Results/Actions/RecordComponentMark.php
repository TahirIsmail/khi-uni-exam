<?php

namespace App\Domain\Results\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Actions\CandidateGuard;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Results\Enums\PublicationStatus;
use App\Domain\Results\Models\ComponentMark;
use App\Domain\Results\Models\ResultComponent;
use App\Domain\Results\Models\ResultPublication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Entering what a candidate was given for a practical, a viva or their internal assessment.
 *
 * This system does not run any of those, so it does not compute them; it records what the
 * department decided, says who recorded it and when, and writes it into the audit chain. A mark can
 * be corrected — a transcription slip is not a marking decision — but not after the result has been
 * published, at which point it is the university's word and changing it is a rescore, not a typo.
 */
final class RecordComponentMark
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $actor, ResultComponent $component, Candidate $candidate, float $marks): ComponentMark
    {
        $component->loadMissing('examination');
        $examination = $component->examination;

        $this->guard->authorise($actor, $examination, 'result.components', 'You cannot enter marks for this examination.');

        if ($component->isPaper()) {
            throw ValidationException::withMessages([
                'component_id' => 'This component is the computer-based paper; its marks come from the marking, not from here.',
            ]);
        }

        if ($candidate->examination_id !== $examination->id) {
            throw ValidationException::withMessages(['candidate_id' => 'That candidate did not sit this examination.']);
        }

        if ($marks < 0 || $marks > $component->max_marks) {
            throw ValidationException::withMessages([
                'marks' => "The mark must be between 0 and {$component->max_marks}.",
            ]);
        }

        if ($this->isPublished($examination->id)) {
            throw ValidationException::withMessages([
                'marks' => 'These results are published. Changing a component mark now is a rescore, not a correction.',
            ]);
        }

        return DB::transaction(function () use ($component, $candidate, $marks, $actor): ComponentMark {
            $existing = ComponentMark::query()
                ->where('component_id', $component->id)->where('candidate_id', $candidate->id)->first();

            $mark = ComponentMark::query()->updateOrCreate(
                ['component_id' => $component->id, 'candidate_id' => $candidate->id],
                ['marks' => $marks, 'entered_by' => $actor->id, 'entered_at' => now()],
            );

            $this->audit->record(
                $existing === null ? 'result.component_mark_entered' : 'result.component_mark_corrected',
                'candidate',
                $candidate->id,
                $existing === null ? null : ['marks' => $existing->marks],
                ['component' => $component->code, 'marks' => $marks],
                null,
                $actor,
                $component->examination->branch_id,
            );

            return $mark;
        });
    }

    private function isPublished(int $examinationId): bool
    {
        return ResultPublication::query()->where('examination_id', $examinationId)
            ->where('status', PublicationStatus::Published->value)->exists();
    }
}
