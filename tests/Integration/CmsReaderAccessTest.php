<?php

/*
 * The MySQL account produced by `php artisan cms:reader-sql` can read the v_cms_* views and
 * nothing else. Runs outside RefreshDatabase: CREATE USER commits implicitly.
 */

use App\Domain\Identity\Models\CmsStaff;
use App\Support\Cms\CmsViews;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

const READER_USER = 'kmu_cms_reader_test';

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

    config(['database.connections.cms.username' => READER_USER, 'database.connections.cms.password' => 'reader-test-password-'.bin2hex(random_bytes(8))]);
    Artisan::call('cms:reader-sql', ['--user' => READER_USER, '--host' => '%']);
    foreach (array_filter(array_map('trim', explode(";\n", trim(Artisan::output())))) as $statement) {
        DB::unprepared(rtrim($statement, ';'));
    }

    DB::purge('cms');
});

afterEach(function () {
    DB::purge('cms');
    DB::unprepared("DROP USER IF EXISTS '".READER_USER."'@'%'");
});

function reader(): Connection
{
    return DB::connection('cms');
}

test('the generated SQL grants SELECT on every CMS view', function () {
    $grants = collect(DB::select("SHOW GRANTS FOR '".READER_USER."'@'%'"))->map(fn ($row) => (string) array_values((array) $row)[0]);

    foreach (CmsViews::names() as $view) {
        expect($grants->contains(fn ($grant) => str_contains($grant, 'GRANT SELECT ON') && str_contains($grant, "`{$view}`")))->toBeTrue("missing grant on {$view}");
    }
    expect($grants->contains(fn ($grant) => preg_match('/INSERT|UPDATE|DELETE|CREATE|DROP|ALL PRIVILEGES/', $grant) === 1))->toBeFalse();
});

test('the reader can read the CMS views', function () {
    foreach (CmsViews::names() as $view) {
        expect(fn () => reader()->table($view)->limit(1)->get())->not->toThrow(Throwable::class);
    }
});

test('the reader cannot read CMS tables directly or this app\'s own tables', function () {
    $cms = config('database.cms_source_database');

    expect(fn () => reader()->table("{$cms}.staff")->count())->toThrow(QueryException::class)
        ->and(fn () => reader()->table('users')->count())->toThrow(QueryException::class)
        ->and(fn () => reader()->table('sso_consumed_tickets')->count())->toThrow(QueryException::class);
});

test('the reader cannot change anything', function () {
    expect(fn () => reader()->table('v_cms_staff')->where('id', 1)->update(['name' => 'changed']))->toThrow(QueryException::class)
        ->and(fn () => reader()->table('v_cms_branches')->insert(['branch_name' => 'x']))->toThrow(QueryException::class)
        ->and(fn () => reader()->statement('CREATE TABLE reader_should_not_create (id INT)'))->toThrow(QueryException::class);
});

test('CMS staff model refuses to save', function () {
    $staff = new CmsStaff;
    $staff->forceFill(['name' => 'x']);

    expect(fn () => $staff->save())->toThrow(LogicException::class);
});
