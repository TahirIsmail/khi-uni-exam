<?php

namespace App\Domain\Identity;

use App\Domain\Identity\Authorization\AccessControl;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The campus (branch) the user is working in.
 *
 * It is set from the campus selected in kmu-cms when they arrive (or their first campus when the CMS
 * shows "All Branches"), kept in the session, and can be switched here if they work in more than one.
 * Everything they see and create belongs to this campus, and it is always one of their own campuses.
 */
final class ActiveBranch
{
    private const KEY = 'active_branch_id';

    public function __construct(
        private readonly AccessControl $access,
        private readonly Session $session,
    ) {}

    /** Called when the user arrives from kmu-cms. */
    public function open(User $user, ?int $cmsBranchId): ?int
    {
        $allowed = $this->access->branchIds($user);
        $branchId = ($cmsBranchId !== null && in_array($cmsBranchId, $allowed, true)) ? $cmsBranchId : ($allowed[0] ?? null);

        $branchId === null ? $this->session->forget(self::KEY) : $this->session->put(self::KEY, $branchId);

        return $branchId;
    }

    public function id(User $user): ?int
    {
        $allowed = $this->access->branchIds($user);
        $stored = $this->session->get(self::KEY);
        if (is_int($stored) && in_array($stored, $allowed, true)) {
            return $stored;
        }

        // No campus in the session (or one the user no longer works in): fall back to their first.
        $first = $allowed[0] ?? null;
        $first === null ? $this->session->forget(self::KEY) : $this->session->put(self::KEY, $first);

        return $first;
    }

    public function switchTo(User $user, int $branchId): bool
    {
        if (! in_array($branchId, $this->access->branchIds($user), true)) {
            return false;
        }
        $this->session->put(self::KEY, $branchId);

        return true;
    }

    /**
     * The user's campuses, for the switcher.
     *
     * @return list<array{id: int, name: string}>
     */
    public function options(User $user): array
    {
        $allowed = $this->access->branchIds($user);
        if ($allowed === []) {
            return [];
        }

        return array_values(DB::connection('cms')->table('v_cms_branches')
            ->whereIn('id', $allowed)->orderBy('branch_name')
            ->get(['id', 'branch_name'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->branch_name])
            ->all());
    }

    public function name(User $user): ?string
    {
        $id = $this->id($user);
        foreach ($this->options($user) as $option) {
            if ($option['id'] === $id) {
                return $option['name'];
            }
        }

        return null;
    }
}
