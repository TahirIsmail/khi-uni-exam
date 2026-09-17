<?php

use App\Domain\Audit\AuditLogger;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithCms;

uses(InteractsWithCms::class);

beforeEach(function () {
    $this->shareCmsConnection();
    $this->main = $this->cmsBranch('Main Campus');
    $this->city = $this->cmsBranch('City Campus');

    $audit = app(AuditLogger::class);
    $this->mainEntry = $audit->record('test.main_campus', 'thing', 1, null, ['note' => '=HYPERLINK("http://evil")'], branchId: $this->main);
    $this->cityEntry = $audit->record('test.city_campus', 'thing', 2, branchId: $this->city);
    $this->globalEntry = $audit->record('test.every_campus', 'thing', 3);
});

test('the audit log needs audit.view', function () {
    $user = $this->adminWith(['qbank.question.view'], $this->main);

    $this->actingAs($user)->get('/admin/audit')->assertForbidden();
    $this->actingAs($user)->get('/admin/audit/export')->assertForbidden();
});

test('viewers see entries of their campuses; entries of no single campus only if they work in every campus', function () {
    $mainViewer = $this->adminWith(['audit.view'], $this->main);
    $everywhere = $this->staffUser([$this->cmsRole('Super Admin', true)]);

    $this->actingAs($mainViewer)->get('/admin/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/AuditLog')
        ->where('canExport', false)
        ->missing('verification')
        ->where('entries.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$this->mainEntry]));

    $this->actingAs($everywhere)->get('/admin/audit')->assertInertia(fn (Assert $page) => $page
        ->where('entries.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === [$this->mainEntry, $this->cityEntry, $this->globalEntry]));
});

test('filters narrow the list and reject malformed values', function () {
    $viewer = $this->staffUser([$this->cmsRole('Super Admin', true)]);

    $this->actingAs($viewer)->get('/admin/audit?action=test.city_campus')->assertInertia(fn (Assert $page) => $page
        ->where('entries.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$this->cityEntry])
        ->where('filters.action', 'test.city_campus'));
    $this->actingAs($viewer)->get('/admin/audit?entity_type=thing&entity_id=3')->assertInertia(fn (Assert $page) => $page
        ->where('entries.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$this->globalEntry]));
    $this->actingAs($viewer)->get('/admin/audit?from=2000-01-01&to=2000-01-02')->assertInertia(fn (Assert $page) => $page->where('entries.total', 0));

    $this->actingAs($viewer)->from('/admin/audit')->get("/admin/audit?action=x' OR 1=1 --")->assertSessionHasErrors('action');
    $this->actingAs($viewer)->from('/admin/audit')->get('/admin/audit?from=yesterday')->assertSessionHasErrors('from');
});

test('the hash chain is checked only when the page asks for it', function () {
    $viewer = $this->staffUser([$this->cmsRole('Super Admin', true)]);
    $version = app(HandleInertiaRequests::class)->version(request());

    $this->actingAs($viewer)->get('/admin/audit', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'admin/AuditLog',
        'X-Inertia-Partial-Data' => 'verification',
    ])->assertOk()->assertJsonPath('props.verification.ok', true)
        ->assertJsonPath('props.verification.checked', 3);
});

test('export gives only visible entries as CSV, neutralises spreadsheet formulas, and is audited', function () {
    $exporter = $this->adminWith(['audit.view', 'audit.export'], $this->main);

    $response = $this->actingAs($exporter)->get('/admin/audit/export');
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $csv = $response->streamedContent();
    $rows = array_map(str_getcsv(...), array_filter(explode("\n", trim($csv))));

    expect(array_column(array_slice($rows, 1), 0))->toBe([(string) $this->mainEntry])
        ->and($csv)->not->toContain('test.city_campus')->not->toContain('test.every_campus')
        ->and($rows[1][9])->toStartWith('{')
        ->and($csv)->toContain('HYPERLINK');

    $exported = DB::table('sec_audit_logs')->where('action', 'audit.exported')->first();
    expect($exported)->not->toBeNull()->and((int) $exported->actor_id)->toBe($exporter->id);
});

test('cells that start with a formula character are prefixed with a quote', function () {
    app(AuditLogger::class)->record('test.formula', 'thing', '=1+1', branchId: $this->main);
    $exporter = $this->adminWith(['audit.view', 'audit.export'], $this->main);

    $csv = $this->actingAs($exporter)->get('/admin/audit/export?action=test.formula')->streamedContent();

    expect($csv)->toContain("'=1+1")->not->toContain(',=1+1');
});
