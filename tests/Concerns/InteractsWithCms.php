<?php

namespace Tests\Concerns;

use App\Domain\Identity\Sso\CmsTicketVerifier;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for tests that need kmu-cms data or sign-on tickets.
 *
 * CMS rows are written to the stand-in CMS database through the default connection, inside the
 * test's RefreshDatabase transaction, so they disappear after each test. The read-only `cms`
 * connection is pointed at the same PDO so the views can see those uncommitted rows.
 */
trait InteractsWithCms
{
    protected function shareCmsConnection(): void
    {
        DB::connection('cms')->setPdo(DB::connection()->getPdo());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function cmsStaff(array $attributes = []): int
    {
        $table = config('database.cms_source_database').'.staff';

        DB::statement("SET SESSION sql_mode = ''");
        $id = DB::table($table)->insertGetId(array_merge([
            'employee_id' => 'EMP-'.bin2hex(random_bytes(4)),
            'name' => 'Ayesha',
            'surname' => 'Khan',
            'email' => 'ayesha.'.bin2hex(random_bytes(3)).'@kmu.test',
            'is_active' => 1,
            'branch_id' => 1,
        ], $attributes));
        DB::statement("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        return (int) $id;
    }

    /**
     * Builds a ticket exactly as kmu-cms does (application/controllers/admin/Assessment.php there).
     *
     * @param  array<string, mixed>  $claims
     */
    protected function cmsTicket(array $claims, ?string $secret = null): string
    {
        $now = time();
        $payload = CmsTicketVerifier::base64UrlEncode((string) json_encode(array_merge([
            'iss' => 'kmu-cms',
            'aud' => 'kmu-assess',
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => bin2hex(random_bytes(32)),
            'redirect' => '/dashboard',
        ], $claims)));

        $key = base64_decode($secret ?? (string) config('services.kmu_cms.sso_secret'), true);

        return $payload.'.'.CmsTicketVerifier::base64UrlEncode(hash_hmac('sha256', $payload, (string) $key, true));
    }
}
