<?php

use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCms;

uses(InteractsWithCms::class);

beforeEach(function () {
    $this->shareCmsConnection();
});

test('logging out ends the session and goes back to the sign-in page', function () {
    $user = $this->staffUser();

    $this->actingAs($user)->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
    expect(DB::table('sec_audit_logs')->where('action', 'identity.logout')->where('actor_id', $user->id)->exists())->toBeTrue();
});

test('logout needs POST (a link or image cannot log someone out)', function () {
    $this->actingAs($this->staffUser())->get('/logout')->assertStatus(405);
    $this->assertAuthenticated();
});
