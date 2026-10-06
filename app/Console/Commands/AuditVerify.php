<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('audit:verify')]
#[Description('Recompute the audit log hash chain and report the first row that was changed, removed or inserted')]
final class AuditVerify extends Command
{
    public function handle(AuditVerifier $verifier): int
    {
        $result = $verifier->verify();

        if ($result['ok']) {
            $head = DB::table('sec_audit_chain_head')->where('id', 1)->value('last_hash');
            // The latest hash is written to the application log daily; keep a copy outside the database
            // (e.g. the log shipper or an emailed report) so the whole chain cannot be silently rebuilt.
            Log::info('Audit chain verified', ['rows' => $result['checked'], 'last_hash' => $head]);
            $this->info("Audit log intact: {$result['checked']} rows, latest hash {$head}.");

            return self::SUCCESS;
        }

        Log::critical('Audit chain broken', $result);
        $this->error("Audit log check FAILED at row {$result['first_broken_id']}: {$result['problem']}.");

        return self::FAILURE;
    }
}
