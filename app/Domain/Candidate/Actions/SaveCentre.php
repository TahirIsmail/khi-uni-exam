<?php

namespace App\Domain\Candidate\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Identity\Authorization\AccessControl;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates an exam centre, in the campus the user is working in. Centres are campus
 * infrastructure, not tied to any one course, so this needs only `centre.manage` for that campus —
 * not the course-scoped exam access blueprints and papers check.
 */
final class SaveCentre
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, code: string, address: ?string, is_active: bool}  $input
     */
    public function __invoke(User $user, int $branchId, ?Centre $centre, array $input): Centre
    {
        if (! $this->access->has($user, 'centre.manage')) {
            throw new AuthorizationException('You cannot manage exam centres.');
        }
        if ($centre !== null && $centre->branch_id !== $branchId) {
            throw new AuthorizationException('That centre belongs to another campus.');
        }

        $exists = Centre::query()->where('branch_id', $branchId)->where('code', $input['code'])
            ->when($centre !== null, fn ($q) => $q->whereKeyNot($centre->id))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['code' => 'A centre with this code already exists on this campus.']);
        }

        return DB::transaction(function () use ($user, $branchId, $centre, $input): Centre {
            $before = $centre?->only(['name', 'code', 'address', 'is_active']);

            if ($centre === null) {
                $centre = Centre::query()->create([...$input, 'branch_id' => $branchId, 'created_by' => $user->id]);
                $this->audit->record('centre.created', 'centre', $centre->id, null, $input, null, $user, $branchId);
            } else {
                $centre->update([...$input, 'updated_by' => $user->id]);
                $this->audit->record('centre.updated', 'centre', $centre->id, $before, $input, null, $user, $branchId);
            }

            return $centre;
        });
    }
}
