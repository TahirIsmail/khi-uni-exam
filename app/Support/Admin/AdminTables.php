<?php

namespace App\Support\Admin;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The admin database's tables (CMS_SOURCE_DATABASE: staff, roles, programmes, courses, settings),
 * reached from the app's own connection by their database-qualified names, the way the v_cms_*
 * views reach them. One connection means one transaction covers both databases.
 */
final class AdminTables
{
    /** "classes" => "`uni_exam_admin`.classes"; an alias ("classes as c") is kept. */
    public static function name(string $table): string
    {
        return config('database.cms_source_database').'.'.$table;
    }

    /**
     * The name for a validation rule (exists, unique): the connection first, or Laravel would read
     * the database name as a connection name.
     */
    public static function rule(string $table): string
    {
        return config('database.default').'.'.self::name($table);
    }

    public static function query(string $table): Builder
    {
        return DB::table(self::name($table));
    }
}
