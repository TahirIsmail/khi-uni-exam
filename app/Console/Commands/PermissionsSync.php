<?php

namespace App\Console\Commands;

use App\Domain\Identity\Authorization\Permissions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('permissions:sync')]
#[Description('Bring sec_permissions in line with the permission catalogue in code')]
final class PermissionsSync extends Command
{
    public function handle(): int
    {
        $now = now();

        foreach (Permissions::CATALOGUE as $group => $permissions) {
            foreach ($permissions as $code => $description) {
                DB::table('sec_permissions')->updateOrInsert(['code' => $code], [
                    'group_name' => $group,
                    'description' => $description,
                    'is_privileged' => in_array($code, Permissions::PRIVILEGED, true),
                    'updated_at' => $now,
                ]);
            }
        }

        $obsolete = DB::table('sec_permissions')->whereNotIn('code', Permissions::codes())->pluck('code')->all();
        foreach ($obsolete as $code) {
            if (DB::table('sec_role_permissions')->where('permission_code', $code)->exists()) {
                $this->warn("{$code} is no longer in the catalogue but is still granted to roles; revoke it first.");

                continue;
            }
            DB::table('sec_permissions')->where('code', $code)->delete();
            $this->line("Removed obsolete permission {$code}.");
        }

        $this->info('Permissions synced: '.count(Permissions::codes()).' in the catalogue.');

        return self::SUCCESS;
    }
}
