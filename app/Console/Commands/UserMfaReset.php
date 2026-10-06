<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('user:mfa-reset {email : Existing local user} {--reason= : Why, e.g. the ticket number (recorded in the audit log)}')]
#[Description('Remove a user\'s authenticator (lost phone) and sign them out everywhere; they set up a new one at next sign-in')]
final class UserMfaReset extends Command
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

        DB::transaction(function () use ($user, $reason, $audit): void {
            $hadAuthenticator = $user->hasEnabledTwoFactorAuthentication();
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $audit->record('identity.mfa.reset', 'user', $user->id, ['authenticator' => $hadAuthenticator], ['authenticator' => false], $reason);
        });

        $this->info("Authenticator removed and sessions ended for {$user->email}.");

        return self::SUCCESS;
    }
}
