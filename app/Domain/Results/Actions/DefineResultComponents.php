<?php

namespace App\Domain\Results\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Actions\CandidateGuard;
use App\Domain\Exam\Models\Examination;
use App\Domain\Results\Enums\PublicationStatus;
use App\Domain\Results\Models\ResultComponent;
use App\Domain\Results\Models\ResultPublication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saying what a subject's result is made of: the department's own list of components and what each
 * is worth.
 *
 * The computer-based paper is always one of them and is added here rather than asked for — it is
 * this system's own, its maximum is the examination's total, and nobody should have to remember to
 * list it. Everything else is the practical, the viva, the internal assessment: whatever KMU's
 * blueprint says for that subject.
 *
 * The whole list is replaced in one go, which is how a blueprint is actually decided — never one
 * component at a time — and is refused once any mark has been entered against it, because a
 * component's maximum changing under a mark already given would silently rewrite a result.
 */
final class DefineResultComponents
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{code: string, name: string, max_marks: float, group: string, min_pass_percentage: float|null}>  $components
     *                                                                                                                                 the entered ones; the paper is added here
     * @return list<ResultComponent>
     */
    public function __invoke(User $actor, Examination $examination, array $components): array
    {
        $this->guard->authorise($actor, $examination, 'result.components', 'You cannot set up components for this examination.');

        if (ResultPublication::query()->where('examination_id', $examination->id)
            ->where('status', PublicationStatus::Published->value)->exists()) {
            throw ValidationException::withMessages([
                'components' => 'These results are published; what they were made of can no longer change.',
            ]);
        }

        $entered = ResultComponent::query()->where('examination_id', $examination->id)->where('source', 'entered')->pluck('id');
        if (DB::table('exm_component_marks')->whereIn('component_id', $entered)->exists()) {
            throw ValidationException::withMessages([
                'components' => 'Marks have already been entered against these components. Remove them before changing what the result is made of.',
            ]);
        }

        foreach ($components as $component) {
            if ($component['code'] === 'theory') {
                throw ValidationException::withMessages([
                    'components' => 'The code "theory" belongs to the computer-based paper, which is added for you.',
                ]);
            }
        }

        return DB::transaction(function () use ($examination, $components, $actor): array {
            ResultComponent::query()->where('examination_id', $examination->id)->delete();

            $rows = [ResultComponent::query()->create([
                'examination_id' => $examination->id,
                'code' => 'theory',
                'name' => 'Theory paper',
                'max_marks' => $examination->total_marks,
                'group' => 'theory',
                // PMC's rule: theory is passed on its own, whatever the practical says.
                'min_pass_percentage' => $examination->pass_percentage,
                'source' => 'cbt',
                'sort_order' => 1,
            ])];

            $order = 1;
            foreach ($components as $component) {
                $rows[] = ResultComponent::query()->create([
                    'examination_id' => $examination->id,
                    'code' => $component['code'],
                    'name' => $component['name'],
                    'max_marks' => $component['max_marks'],
                    'group' => $component['group'],
                    'min_pass_percentage' => $component['min_pass_percentage'],
                    'source' => 'entered',
                    'sort_order' => ++$order,
                ]);
            }

            $this->audit->record(
                'result.components_defined',
                'examination',
                $examination->id,
                null,
                ['components' => array_map(fn (ResultComponent $c): array => ['code' => $c->code, 'maxMarks' => $c->max_marks, 'group' => $c->group], $rows)],
                null,
                $actor,
                $examination->branch_id,
            );

            return $rows;
        });
    }
}
