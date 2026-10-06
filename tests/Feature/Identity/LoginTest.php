<?php

use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCms;

uses(InteractsWithCms::class);

beforeEach(function () {
    $this->shareCmsConnection();
    $this->branch = $this->cmsBranch();
});

test('the sign-in page is shown to guests, and signed-in staff are sent on', function () {
    $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->component('auth/Login'));
    $this->actingAs($this->staffUser([], $this->branch))->get('/login')->assertRedirect('/dashboard');
});

test('staff sign in with their email and password', function () {
    $user = $this->staffUser([], $this->branch);

    $this->signIn($user, 'a-good-password')->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

test('the email is not case sensitive', function () {
    $user = $this->staffUser([], $this->branch);
    $this->signIn($user, 'a-good-password');
    auth()->logout();

    $this->post('/login', ['email' => strtoupper($user->email), 'password' => 'a-good-password'])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
});

test('a wrong password, an unknown email or an account with no password is refused the same way', function () {
    $user = $this->staffUser([], $this->branch);
    $this->signIn($user, 'a-good-password');
    auth()->logout();

    $this->post('/login', ['email' => $user->email, 'password' => 'not-the-password'])->assertSessionHasErrors(['email' => 'The email or password is wrong.']);
    $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'a-good-password'])->assertSessionHasErrors(['email' => 'The email or password is wrong.']);

    $noPassword = $this->staffUser([], $this->branch);
    $this->post('/login', ['email' => $noPassword->email, 'password' => ''])->assertSessionHasErrors('password');
    $this->post('/login', ['email' => $noPassword->email, 'password' => 'anything-at-all'])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a staff member switched off under Setup cannot sign in', function () {
    $user = $this->staffUser([], $this->branch);
    DB::table(config('database.cms_source_database').'.staff')->where('id', $user->cms_staff_id)->update(['is_active' => 0]);

    $this->signIn($user)->assertSessionHasErrors(['email' => 'This account is switched off. Ask the administrator.']);
    $this->assertGuest();
});

test('guessing passwords is slowed down per email', function () {
    $user = $this->staffUser([], $this->branch);

    foreach (range(1, 5) as $ignored) {
        $this->post('/login', ['email' => $user->email, 'password' => 'guess-'.$ignored]);
    }
    $this->post('/login', ['email' => $user->email, 'password' => 'guess-6'])->assertStatus(429);
});
