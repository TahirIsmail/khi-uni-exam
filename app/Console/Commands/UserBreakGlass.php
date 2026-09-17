<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('user:break-glass {email : Existing local user} {--revoke : Remove break-glass status} {--reason= : Why (recorded in the audit log)}')]
#[Description('Grant or revoke emergency administrator status for a local account (all permissions, MFA required)')]
final class UserBreakGlass extends Command
{
    public function handle(AuditLogger $audit): int
    {
        $user = User::query()->where('email', mb_strtolower((string) $this->argument('email')))->first();
        if ($user === null) {
            $this->error('No local user with that email.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('A --reason is required.');

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');

        DB::transaction(function () use ($user, $grant, $reason, $audit): void {
            $old = ['is_break_glass' => $user->is_break_glass];
            $user->forceFill(['is_break_glass' => $grant])->save();
            $audit->record($grant ? 'admin.break_glass.granted' : 'admin.break_glass.revoked', 'user', $user->id, $old, ['is_break_glass' => $grant], $reason);
        });

        $this->info(($grant ? 'Granted' : 'Revoked')." break-glass status for {$user->email}.");

        return self::SUCCESS;
    }
}
