<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\AuditVerifier;
use App\Domain\Audit\Queries\AuditLogSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogFilterRequest;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(AuditLogFilterRequest $request, AuditLogSearch $search, AuditVerifier $verifier): Response
    {
        return Inertia::render('admin/AuditLog', [
            'entries' => $search->paginate($request->user(), $request->filters()),
            'filters' => $request->filters(),
            'actions' => $search->actions(),
            'canExport' => $request->user()->can('audit.export'),
            // Recomputing the chain reads every row, so it runs only when the page asks for it (partial reload).
            'verification' => Inertia::optional(fn () => $verifier->verify()),
        ]);
    }

    public function export(AuditLogFilterRequest $request, AuditLogSearch $search, AuditLogger $audit): StreamedResponse
    {
        $filters = $request->filters();
        $audit->record('audit.exported', 'audit_log', null, null, ['filters' => array_filter($filters, fn (?string $v): bool => $v !== null)]);

        return response()->streamDownload(function () use ($search, $request, $filters): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['id', 'occurred_at', 'actor', 'actor_email', 'action', 'entity_type', 'entity_id', 'branch_id', 'old_values', 'new_values', 'reason', 'ip', 'request_id'], escape: '');
            foreach ($search->cursor($request->user(), $filters) as $entry) {
                fputcsv($out, array_map(self::csvSafe(...), [
                    $entry['id'], $entry['occurredAt'], $entry['actorName'] ?? $entry['actorType'], $entry['actorEmail'], $entry['action'],
                    $entry['entityType'], $entry['entityId'], $entry['branchId'],
                    $entry['oldValues'] === null ? null : json_encode($entry['oldValues'], JSON_UNESCAPED_UNICODE),
                    $entry['newValues'] === null ? null : json_encode($entry['newValues'], JSON_UNESCAPED_UNICODE),
                    $entry['reason'], $entry['ip'], $entry['requestId'],
                ]), escape: '');
            }
            fclose($out);
        }, 'audit-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Stops spreadsheet formula injection: a cell starting with = + - @ is prefixed with a quote. */
    private static function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
