<?php

/*
 * Architecture rules that every future module must follow. They run with the normal
 * test suite, so a violation fails CI instead of relying on code review alone.
 */

use Symfony\Component\Finder\Finder;

arch('no debugging or legacy php functions')->preset()->php();

arch('no insecure php functions (weak hashing, weak randomness, eval, shell, unserialize)')->preset()->security();

arch('controllers never talk to the database directly')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'PDO']);

arch('env() is only read inside config files')
    ->expect(['App', 'Database', 'Routes'])
    ->not->toUse('env');

/*
 * SQL injection guard: raw SQL fragments are the only place user input could reach a query
 * unescaped. They are forbidden in app code unless the line is explicitly marked as reviewed
 * with a "// raw-sql-reviewed: <reason>" comment (values must still be passed as bindings).
 */
function isUnreviewedRawSql(string $line): bool
{
    $rawSqlPattern = '/\b(DB::raw|DB::unprepared|DB::statement|DB::select|DB::insert|DB::update|DB::delete|selectRaw|whereRaw|orWhereRaw|havingRaw|orHavingRaw|orderByRaw|groupByRaw|fromRaw|joinRaw)\s*\(/';

    return preg_match($rawSqlPattern, $line) === 1 && ! str_contains($line, 'raw-sql-reviewed:');
}

test('the raw sql guard recognises unsafe and reviewed lines', function () {
    expect(isUnreviewedRawSql('$q->whereRaw("email = \'$email\'");'))->toBeTrue()
        ->and(isUnreviewedRawSql('DB::statement($sql);'))->toBeTrue()
        ->and(isUnreviewedRawSql('$q->orderByRaw(\'FIELD(id, ?)\', [$id]); // raw-sql-reviewed: bound value'))->toBeFalse()
        ->and(isUnreviewedRawSql('$q->where(\'email\', $email);'))->toBeFalse();
});

test('raw sql is not used in app code without an explicit review marker', function () {
    $violations = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
        foreach (file($file->getRealPath()) ?: [] as $number => $line) {
            if (isUnreviewedRawSql($line)) {
                $violations[] = $file->getRelativePathname().':'.($number + 1).' '.trim($line);
            }
        }
    }

    expect($violations)->toBe([]);
});
