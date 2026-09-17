<?php

use App\Domain\Audit\AuditChain;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\AuditVerifier;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCms;

uses(InteractsWithCms::class);

beforeEach(function () {
    $this->shareCmsConnection();
});

function audit(): AuditLogger
{
    return app(AuditLogger::class);
}

test('entries are chained: each row stores the previous row\'s hash', function () {
    $first = audit()->record('test.first', 'thing', 1, null, ['a' => 1]);
    $second = audit()->record('test.second', 'thing', 1, ['a' => 1], ['a' => 2], 'because');

    $rows = DB::table('sec_audit_logs')->whereIn('id', [$first, $second])->orderBy('id')->get();

    expect($rows[0]->prev_hash)->toBe(AuditChain::GENESIS)
        ->and($rows[1]->prev_hash)->toBe($rows[0]->row_hash)
        ->and(DB::table('sec_audit_chain_head')->value('last_hash'))->toBe($rows[1]->row_hash)
        ->and(app(AuditVerifier::class)->verify())->toMatchArray(['ok' => true, 'checked' => 2]);
});

test('the actor, request and old/new values are recorded', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $id = audit()->record('qbank.question.approved', 'question_version', 42, ['status' => 'under_review'], ['status' => 'approved'], 'reviewed twice');
    $row = DB::table('sec_audit_logs')->find($id);

    expect($row->actor_type)->toBe('staff')
        ->and((int) $row->actor_id)->toBe($user->id)
        ->and($row->entity_type)->toBe('question_version')
        ->and($row->entity_id)->toBe('42')
        ->and(json_decode($row->old_values, true))->toBe(['status' => 'under_review'])
        ->and(json_decode($row->new_values, true))->toBe(['status' => 'approved'])
        ->and($row->reason)->toBe('reviewed twice')
        ->and($row->ip)->toBe('127.0.0.1');
});

test('secrets are never written to the audit log', function () {
    $id = audit()->record('test.secret', 'user', 1, ['password' => 'old-secret'], ['nested' => ['two_factor_secret' => 'ABC', 'name' => 'ok'], 'ticket' => 'xyz']);
    $row = DB::table('sec_audit_logs')->find($id);

    expect($row->old_values.$row->new_values)->not->toContain('old-secret')->not->toContain('ABC')->not->toContain('xyz')
        ->and(json_decode($row->new_values, true)['nested']['name'])->toBe('ok');
});

test('audit rows cannot be updated or deleted, even with direct SQL', function () {
    $id = audit()->record('test.locked');

    expect(fn () => DB::table('sec_audit_logs')->where('id', $id)->update(['action' => 'tampered']))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('sec_audit_logs')->where('id', $id)->delete())->toThrow(QueryException::class, 'append-only');
});

test('verification finds a forged row inserted without the logger', function () {
    audit()->record('test.real');
    $head = DB::table('sec_audit_chain_head')->first();

    $forgedId = DB::table('sec_audit_logs')->insertGetId([
        'occurred_at' => now()->format('Y-m-d H:i:s.v'), 'actor_type' => 'system', 'action' => 'test.forged',
        'prev_hash' => $head->last_hash, 'row_hash' => str_repeat('a', 64),
    ]);

    expect(app(AuditVerifier::class)->verify())->toMatchArray(['ok' => false, 'first_broken_id' => $forgedId]);
});

test('verification notices a row removed from the end of the chain', function () {
    audit()->record('test.one');
    audit()->record('test.two');
    DB::table('sec_audit_chain_head')->update(['last_hash' => str_repeat('b', 64)]);

    expect(app(AuditVerifier::class)->verify()['ok'])->toBeFalse();
});

test('the audit:verify command fails loudly on a broken chain', function () {
    audit()->record('test.one');
    DB::table('sec_audit_chain_head')->update(['last_hash' => str_repeat('c', 64)]);

    $this->artisan('audit:verify')->assertFailed();
});

test('SSO sign-in and refusals are audited', function () {
    $staffId = $this->cmsStaff();
    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $staffId])]);
    $user = User::query()->where('cms_staff_id', $staffId)->firstOrFail();
    auth()->logout();
    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => 987654])]);

    expect(DB::table('sec_audit_logs')->where('action', 'identity.sso.login')->where('entity_id', (string) $user->id)->where('actor_id', $user->id)->exists())->toBeTrue()
        ->and(json_decode((string) DB::table('sec_audit_logs')->where('action', 'identity.sso.refused')->value('new_values'), true))->toBe(['reason' => 'unknown_staff'])
        ->and(app(AuditVerifier::class)->verify()['ok'])->toBeTrue();
});

test('kmu-cms reads the audit log with actor names through v_cms_audit_entries', function () {
    $user = User::factory()->create(['name' => 'Dr Sana Ali']);
    $user->forceFill(['cms_staff_id' => 77])->save();
    $id = audit()->record('test.for_cms', 'thing', 9, null, ['a' => 1], 'why', $user, 3);

    $row = DB::table('v_cms_audit_entries')->where('id', $id)->first();

    expect($row->actor_name)->toBe('Dr Sana Ali')
        ->and((int) $row->actor_staff_id)->toBe(77)
        ->and((int) $row->branch_id)->toBe(3)
        ->and($row->reason)->toBe('why');
});

test('the SQL for the audit reader account grants SELECT on the audit view only', function () {
    config(['database.cms_audit_reader_password' => 'short']);
    $this->artisan('cms:audit-reader-sql')->assertFailed();

    config(['database.cms_audit_reader_password' => str_repeat('x', 24)]);
    $this->artisan('cms:audit-reader-sql', ['--user' => 'kmu_audit_reader'])
        ->expectsOutputToContain('GRANT SELECT ON `'.config('database.connections.mysql.database').'`.`v_cms_audit_entries` TO')
        ->doesntExpectOutputToContain('sec_audit_logs')
        ->assertSuccessful();

    $this->artisan('cms:audit-reader-sql', ['--user' => "x'; DROP USER root; --"])->assertFailed();
});

test('every response has a request id that audit entries reference', function () {
    $response = $this->get('/login');

    expect($response->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');
});
