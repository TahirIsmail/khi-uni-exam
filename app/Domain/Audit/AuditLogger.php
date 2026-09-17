<?php

namespace App\Domain\Audit;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Writes audit entries (blueprint 22.5). Call it inside the same transaction as the change it
 * describes, so a change is never saved without its audit entry (and vice versa).
 *
 * Secrets are removed from old/new values; the session id is stored only as a hash.
 */
final class AuditLogger
{
    private const REDACTED_KEYS = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'ticket', 'sso_secret', 'token'];

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        string $action,
        ?string $entityType = null,
        string|int|null $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
        ?User $actor = null,
    ): int {
        $request = request();
        $authenticated = Auth::user();
        $actor ??= $authenticated instanceof User ? $authenticated : null;

        $row = [
            'occurred_at' => now()->format('Y-m-d H:i:s.v'),
            'actor_type' => $actor instanceof User ? 'staff' : 'system',
            'actor_id' => $actor instanceof User ? $actor->id : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'old_values' => $oldValues === null ? null : $this->redact($oldValues),
            'new_values' => $newValues === null ? null : $this->redact($newValues),
            'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'session_hash' => $request->hasSession() ? hash('sha256', $request->session()->getId()) : null,
            'request_id' => $request->attributes->get('request_id'),
        ];

        return DB::transaction(function () use ($row): int {
            $head = DB::table('sec_audit_chain_head')->where('id', 1)->lockForUpdate()->first();
            $previous = $head !== null ? (string) $head->last_hash : AuditChain::GENESIS;
            $hash = AuditChain::hash($previous, $row);

            $id = (int) DB::table('sec_audit_logs')->insertGetId(array_merge($row, [
                'old_values' => $row['old_values'] === null ? null : json_encode($row['old_values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'new_values' => $row['new_values'] === null ? null : json_encode($row['new_values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'prev_hash' => $previous,
                'row_hash' => $hash,
            ]));

            DB::table('sec_audit_chain_head')->where('id', 1)->update(['last_log_id' => $id, 'last_hash' => $hash]);

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
