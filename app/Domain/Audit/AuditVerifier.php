<?php

namespace App\Domain\Audit;

use Illuminate\Support\Facades\DB;

/**
 * Recomputes the audit hash chain from the first row.
 */
final class AuditVerifier
{
    /**
     * @return array{ok: bool, checked: int, first_broken_id: int|null, problem: string|null}
     */
    public function verify(): array
    {
        $expectedPrevious = AuditChain::GENESIS;
        $checked = 0;
        $result = null;

        DB::table('sec_audit_logs')->orderBy('id')->chunk(1000, function ($rows) use (&$expectedPrevious, &$checked, &$result): bool {
            foreach ($rows as $row) {
                $values = (array) $row;

                if ($values['prev_hash'] !== $expectedPrevious) {
                    $result = ['id' => (int) $values['id'], 'problem' => 'previous hash does not match the row before it (a row was removed or inserted out of order)'];

                    return false;
                }
                if (! hash_equals(AuditChain::hash($expectedPrevious, $values), (string) $values['row_hash'])) {
                    $result = ['id' => (int) $values['id'], 'problem' => 'row content does not match its hash (the row was changed)'];

                    return false;
                }

                $expectedPrevious = (string) $values['row_hash'];
                $checked++;
            }

            return true;
        });

        if ($result === null) {
            $head = DB::table('sec_audit_chain_head')->where('id', 1)->first();
            if ($head !== null && $head->last_hash !== $expectedPrevious) {
                $result = ['id' => $head->last_log_id !== null ? (int) $head->last_log_id : null, 'problem' => 'the newest row(s) are missing'];
            }
        }

        return [
            'ok' => $result === null,
            'checked' => $checked,
            'first_broken_id' => $result['id'] ?? null,
            'problem' => $result['problem'] ?? null,
        ];
    }
}
