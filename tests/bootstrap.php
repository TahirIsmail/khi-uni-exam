<?php

/*
 * Test bootstrap: before any test runs, recreate the stand-in kmu-cms database
 * (CMS_SOURCE_DATABASE, e.g. kmu_cms_testing) from database/admin/schema.sql, so the v_cms_*
 * views can be created by migrations and tests can add CMS rows. Structure only, no data.
 */

require __DIR__.'/../vendor/autoload.php';

$cmsDatabase = getenv('CMS_SOURCE_DATABASE') ?: '';

if (preg_match('/_testing$/', $cmsDatabase) !== 1) {
    fwrite(STDERR, "CMS_SOURCE_DATABASE must name a disposable *_testing database (got '{$cmsDatabase}').\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306'),
    getenv('DB_USERNAME') ?: 'root',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$pdo->exec("DROP DATABASE IF EXISTS `{$cmsDatabase}`");
$pdo->exec("CREATE DATABASE `{$cmsDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$cmsDatabase}`");
$pdo->exec((string) file_get_contents(__DIR__.'/../database/admin/schema.sql'));
