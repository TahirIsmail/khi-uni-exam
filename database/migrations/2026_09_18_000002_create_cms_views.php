<?php

use App\Support\Cms\CmsViews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Read-only views over the kmu-cms database (name from database.cms_source_database). The CMS
 * database must be on the same MySQL server and already contain the academic-structure tables
 * (kmu-cms migrations 20260917_0001-0005).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (CmsViews::definitions((string) config('database.cms_source_database')) as $name => $select) {
            DB::statement("CREATE OR REPLACE SQL SECURITY DEFINER VIEW `{$name}` AS {$select}");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(CmsViews::names()) as $name) {
            DB::statement("DROP VIEW IF EXISTS `{$name}`");
        }
    }
};
