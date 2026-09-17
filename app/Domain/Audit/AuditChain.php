<?php

namespace App\Domain\Audit;

/**
 * Hash chain for the audit log: row_hash = SHA-256(prev_hash . canonical row).
 *
 * The canonical form is built the same way when writing and when verifying from database rows:
 * JSON values are decoded and re-encoded with sorted keys, because MySQL may store JSON keys in a
 * different order than they were written.
 */
final class AuditChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Columns covered by the hash, in order. */
    private const FIELDS = [
        'occurred_at', 'actor_type', 'actor_id', 'action', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'reason', 'ip', 'user_agent', 'session_hash', 'request_id',
    ];

    /**
     * @param  array<string, mixed>  $row
     */
    public static function hash(string $previousHash, array $row): string
    {
        return hash('sha256', $previousHash.self::canonical($row));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function canonical(array $row): string
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $row[$field] ?? null;

            if (in_array($field, ['old_values', 'new_values'], true) && $value !== null) {
                $value = self::sorted(is_string($value) ? json_decode($value, true) : $value);
            } elseif ($field === 'actor_id' && $value !== null) {
                $value = (int) $value;
            } elseif ($value !== null) {
                $value = (string) $value;
            }

            $values[$field] = $value;
        }

        return (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_int($value) || is_float($value) || is_bool($value) || $value === null ? $value : (string) $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
