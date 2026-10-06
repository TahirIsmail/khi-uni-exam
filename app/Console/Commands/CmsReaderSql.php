<?php

namespace App\Console\Commands;

use App\Support\Cms\CmsViews;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cms:reader-sql {--user= : MySQL user (default CMS_DB_USERNAME)} {--host=localhost : Host part of the MySQL account}')]
#[Description('Print SQL that creates the read-only MySQL user for kmu-cms data (SELECT on the v_cms_* views only)')]
final class CmsReaderSql extends Command
{
    public function handle(): int
    {
        $user = (string) ($this->option('user') ?: config('database.connections.cms.username'));
        $host = (string) $this->option('host');
        $password = (string) config('database.connections.cms.password');
        $database = (string) config('database.connections.mysql.database');

        if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $user) !== 1 || preg_match('/^[A-Za-z0-9_.%-]{1,255}$/', $host) !== 1) {
            $this->error('User may contain letters, digits and underscores; host letters, digits, dots, % and hyphens.');

            return self::FAILURE;
        }
        if (strlen($password) < 20) {
            $this->error('Set CMS_DB_PASSWORD to a random value of at least 20 characters first.');

            return self::FAILURE;
        }

        $account = "'{$user}'@'{$host}'";
        $quotedPassword = "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $password)."'";
        $schema = '`'.str_replace('`', '``', $database).'`';

        $lines = [
            "CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY {$quotedPassword};",
            "ALTER USER {$account} IDENTIFIED BY {$quotedPassword};",
            "REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$account};",
        ];
        foreach (CmsViews::names() as $view) {
            $lines[] = "GRANT SELECT ON {$schema}.`{$view}` TO {$account};";
        }

        $this->line(implode(PHP_EOL, $lines));

        return self::SUCCESS;
    }
}
