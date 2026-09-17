<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('sso:purge-tickets')]
#[Description('Delete used CMS sign-on ticket ids that expired more than a day ago (scheduled daily)')]
final class PurgeSsoTickets extends Command
{
    public function handle(): int
    {
        $deleted = DB::table('sso_consumed_tickets')->where('expires_at', '<', now()->subDay())->delete();

        $this->info("Deleted {$deleted} expired ticket ids.");

        return self::SUCCESS;
    }
}
