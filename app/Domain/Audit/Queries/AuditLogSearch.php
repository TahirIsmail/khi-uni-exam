<?php

namespace App\Domain\Audit\Queries;

use App\Domain\Identity\Authorization\AccessControl;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use stdClass;

/**
 * Branch-wise search of the audit log: entries of the viewer's branches, plus entries tied to no
 * branch (role grants, sign-ins) only for viewers who work in every active branch.
 */
final class AuditLogSearch
{
    public function __construct(private readonly AccessControl $access) {}

    /**
     * @param  array{action?: string|null, actor?: string|null, entity_type?: string|null, entity_id?: string|null, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(User $viewer, array $filters, int $perPage = 50): LengthAwarePaginator
    {
        return $this->query($viewer, $filters)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (stdClass $row): array => $this->present($row));
    }

    /**
     * @param  array{action?: string|null, actor?: string|null, entity_type?: string|null, entity_id?: string|null, from?: string|null, to?: string|null}  $filters
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function cursor(User $viewer, array $filters): LazyCollection
    {
        return $this->query($viewer, $filters)->lazyById(1000, 'l.id', 'id')->map(fn (stdClass $row): array => $this->present($row));
    }

    /**
     * Distinct actions, for the filter list.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return array_values(DB::table('sec_audit_logs')->distinct()->orderBy('action')->pluck('action')->map(fn (mixed $a): string => (string) $a)->all());
    }

    /**
     * @param  array{action?: string|null, actor?: string|null, entity_type?: string|null, entity_id?: string|null, from?: string|null, to?: string|null}  $filters
     */
    private function query(User $viewer, array $filters): Builder
    {
        $branches = $this->access->branchIds($viewer);
        $everyBranch = $this->access->coversAllBranches($viewer);

        $query = DB::table('sec_audit_logs as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.actor_id')
            ->select(['l.id', 'l.occurred_at', 'l.actor_type', 'l.actor_id', 'u.name as actor_name', 'u.email as actor_email', 'l.action', 'l.entity_type', 'l.entity_id', 'l.branch_id', 'l.old_values', 'l.new_values', 'l.reason', 'l.ip', 'l.request_id'])
            ->where(function (Builder $q) use ($branches, $everyBranch): void {
                $q->whereIn('l.branch_id', $branches === [] ? [0] : $branches);
                if ($everyBranch) {
                    $q->orWhereNull('l.branch_id');
                }
            });

        if (($filters['action'] ?? '') !== '') {
            $query->where('l.action', (string) $filters['action']);
        }
        if (($filters['actor'] ?? '') !== '') {
            $like = '%'.addcslashes((string) $filters['actor'], '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('u.name', 'like', $like)->orWhere('u.email', 'like', $like));
        }
        if (($filters['entity_type'] ?? '') !== '') {
            $query->where('l.entity_type', (string) $filters['entity_type']);
        }
        if (($filters['entity_id'] ?? '') !== '') {
            $query->where('l.entity_id', (string) $filters['entity_id']);
        }
        if (($filters['from'] ?? '') !== '') {
            $query->where('l.occurred_at', '>=', Carbon::parse((string) $filters['from'])->startOfDay());
        }
        if (($filters['to'] ?? '') !== '') {
            $query->where('l.occurred_at', '<=', Carbon::parse((string) $filters['to'])->endOfDay());
        }

        return $query->orderByDesc('l.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(stdClass $row): array
    {
        return [
            'id' => (int) $row->id,
            'occurredAt' => Carbon::parse((string) $row->occurred_at)->toIso8601String(),
            'actorType' => (string) $row->actor_type,
            'actorName' => $row->actor_name === null ? null : (string) $row->actor_name,
            'actorEmail' => $row->actor_email === null ? null : (string) $row->actor_email,
            'action' => (string) $row->action,
            'entityType' => $row->entity_type === null ? null : (string) $row->entity_type,
            'entityId' => $row->entity_id === null ? null : (string) $row->entity_id,
            'branchId' => $row->branch_id === null ? null : (int) $row->branch_id,
            'oldValues' => $row->old_values === null ? null : json_decode((string) $row->old_values, true),
            'newValues' => $row->new_values === null ? null : json_decode((string) $row->new_values, true),
            'reason' => $row->reason === null ? null : (string) $row->reason,
            'ip' => $row->ip === null ? null : (string) $row->ip,
            'requestId' => $row->request_id === null ? null : (string) $row->request_id,
        ];
    }
}
