<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cms:audit-reader-sql {--user=kmu_audit_reader : MySQL user} {--host=localhost : Host part of the MySQL account}')]
#[Description('Print SQL that creates the MySQL user kmu-cms uses for the Exam Audit Log screen (SELECT on v_cms_audit_entries only)')]
final class CmsAuditReaderSql extends Command
{
    public function handle(): int
    {
        $user = (string) $this->option('user');
        $host = (string) $this->option('host');
        $password = (string) config('database.cms_audit_reader_password');
        $database = (string) config('database.connections.mysql.database');

        if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $user) !== 1 || preg_match('/^[A-Za-z0-9_.%-]{1,255}$/', $host) !== 1) {
            $this->error('User may contain letters, digits and underscores; host letters, digits, dots, % and hyphens.');

            return self::FAILURE;
        }
        if (strlen($password) < 20) {
            $this->error('Set CMS_AUDIT_READER_PASSWORD to a random value of at least 20 characters first (and the same password in kmu-cms config kmu_assessment.audit_db).');

            return self::FAILURE;
        }

        $account = "'{$user}'@'{$host}'";
        $quotedPassword = "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $password)."'";
        $schema = '`'.str_replace('`', '``', $database).'`';

        $this->line(implode(PHP_EOL, [
            "CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY {$quotedPassword};",
            "ALTER USER {$account} IDENTIFIED BY {$quotedPassword};",
            "REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$account};",
            "GRANT SELECT ON {$schema}.`v_cms_audit_entries` TO {$account};",
        ]));

        return self::SUCCESS;
    }
}
