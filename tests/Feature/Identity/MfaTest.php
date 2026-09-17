<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\InteractsWithCms;

uses(InteractsWithCms::class);

beforeEach(function () {
    $this->shareCmsConnection();
    $this->branch = $this->cmsBranch();
    RateLimiter::clear('mfa');
});

function enrol(User $user): User
{
    app(EnableTwoFactorAuthentication::class)($user, true);
    // The previous code (one 30-second step back , still accepted) so the current one stays unused for the test.
    app(ConfirmTwoFactorAuthentication::class)($user->fresh(), totp($user->fresh(), -1));
    session()->flush();

    return $user->fresh();
}

function totp(User $user, int $offsetSteps = 0): string
{
    $engine = app(Google2FA::class);

    return $engine->oathTotp(decrypt($user->two_factor_secret), $engine->getTimestamp() + $offsetSteps);
}

function audited(string $action, User $user): bool
{
    return DB::table('sec_audit_logs')->where('action', $action)->where('entity_id', (string) $user->id)->exists();
}

test('a privileged user without an authenticator must set one up before anything else', function () {
    $admin = $this->staffUser([$this->cmsRole('Super Admin', true)], $this->branch);

    $this->actingAs($admin)->get('/dashboard')->assertRedirect('/mfa/setup');
    $this->actingAs($admin)->get('/admin/roles')->assertRedirect('/mfa/setup');
    $this->actingAs($admin)->put('/admin/roles/1', ['permissions' => []])->assertRedirect('/mfa/setup');
    $this->actingAs($admin)->get('/admin/audit/export', ['Accept' => 'application/json'])->assertForbidden()->assertJsonPath('redirect', route('mfa.setup'));
    $this->actingAs($admin)->get('/settings/security')->assertRedirect('/mfa/setup');
    $this->actingAs($admin)->post('/logout')->assertRedirect();
});

test('staff without privileged permissions and without 2FA are not asked for MFA', function () {
    $role = $this->cmsRole('Faculty');
    $this->grant($role, 'qbank.question.view', 'qbank.question.create');

    $this->actingAs($this->staffUser([$role], $this->branch))->get('/dashboard')->assertOk();
});

test('holding one privileged permission is enough to require MFA', function () {
    $role = $this->cmsRole('Examinations');
    $this->grant($role, 'qbank.question.view', 'exam.publish');

    $this->actingAs($this->staffUser([$role], $this->branch))->get('/dashboard')->assertRedirect('/mfa/setup');
});

test('setting up an authenticator: wrong code refused, right code enrols, recovery codes shown once', function () {
    $admin = $this->staffUser([$this->cmsRole('Super Admin', true)], $this->branch);
    $this->actingAs($admin)->get('/admin/roles');

    $this->actingAs($admin)->get('/mfa/setup')->assertInertia(fn ($page) => $page->component('auth/MfaSetup')->where('started', false)->where('required', true));
    $this->actingAs($admin)->post('/mfa/setup')->assertRedirect('/mfa/setup');
    $admin->refresh();
    expect($admin->two_factor_secret)->not->toBeNull()->and($admin->two_factor_confirmed_at)->toBeNull();

    $this->actingAs($admin)->get('/mfa/setup')->assertInertia(fn ($page) => $page->where('started', true)
        ->where('setupKey', decrypt($admin->two_factor_secret))
        ->where('qrCodeSvg', fn ($svg) => str_starts_with((string) $svg, '<svg')));

    $wrong = totp($admin) === '000000' ? '111111' : '000000';
    $this->actingAs($admin)->from('/mfa/setup')->post('/mfa/setup/confirm', ['code' => $wrong])->assertSessionHasErrors('code');
    $this->actingAs($admin)->from('/mfa/setup')->post('/mfa/setup/confirm', ['code' => '12ab56'])->assertSessionHasErrors('code');
    expect($admin->fresh()->two_factor_confirmed_at)->toBeNull();

    $this->actingAs($admin)->post('/mfa/setup/confirm', ['code' => totp($admin)])->assertRedirect('/mfa/recovery-codes');
    expect($admin->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and(audited('identity.mfa.enrolled', $admin))->toBeTrue();

    $this->actingAs($admin)->get('/mfa/recovery-codes')->assertInertia(fn ($page) => $page->component('auth/MfaRecoveryCodes')
        ->where('codes', fn ($codes) => count($codes) === 8)
        ->where('continueUrl', url('/admin/roles')));
    $this->actingAs($admin)->get('/mfa/recovery-codes')->assertRedirect();

    $this->actingAs($admin)->get('/admin/roles')->assertOk();
});

test('an authenticator that is already set up cannot be replaced from a session', function () {
    $admin = enrol($this->staffUser([$this->cmsRole('Super Admin', true)], $this->branch));
    $secret = $admin->two_factor_secret;

    $this->actingAs($admin)->get('/mfa/setup')->assertRedirect('/mfa/challenge');
    $this->actingAs($admin)->post('/mfa/setup')->assertForbidden();
    $this->actingAs($admin)->post('/mfa/setup/confirm', ['code' => totp($admin)])->assertForbidden();

    expect($admin->fresh()->two_factor_secret)->toBe($secret);
});

test('an enrolled user coming from kmu-cms passes the challenge once per session and returns where they were going', function () {
    $admin = enrol($this->staffUser([$this->cmsRole('Super Admin', true)], $this->branch));

    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $admin->cms_staff_id, 'redirect' => '/admin/audit'])])->assertRedirect('/admin/audit');
    $this->get('/admin/audit')->assertRedirect('/mfa/challenge');
    $this->get('/mfa/challenge')->assertInertia(fn ($page) => $page->component('auth/MfaChallenge'));

    $this->from('/mfa/challenge')->post('/mfa/challenge', ['code' => totp($admin) === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
    expect(audited('identity.mfa.failed', $admin))->toBeTrue();

    $this->post('/mfa/challenge', ['code' => totp($admin)])->assertRedirect('/admin/audit');
    $this->get('/admin/audit')->assertOk();
    $this->get('/admin/roles')->assertOk();
    expect(audited('identity.mfa.passed', $admin))->toBeTrue();
});

test('an authenticator code cannot be used twice', function () {
    $admin = enrol($this->staffUser([$this->cmsRole('Super Admin', true)], $this->branch));
    $code = totp($admin, 1);

    $this->actingAs($admin)->get('/dashboard')->assertRedirect('/mfa/challenge');
    $this->post('/mfa/challenge', ['code' => $code])->assertSessionHasNoErrors();

    $this->flushSession();
    $this->actingAs($admin)->from('/mfa/challenge')->post('/mfa/challenge', ['code' => $code])->assertSessionHasErrors('code');
});

test('a code from the next 30-second step also works only once, and an older code is refused after a newer one', function () {
    $admin = enrol($this->staffUser([$this->cmsRole('Super Admin', true)], $this->branch));
    $provider = app(TwoFactorAuthenticationProvider::class);
    $secret = decrypt($admin->two_factor_secret);

    expect($provider->verify($secret, totp($admin, 1)))->toBeTrue()
        ->and($provider->verify($secret, totp($admin, 1)))->toBeFalse()
        ->and($provider->verify($secret, totp($admin)))->toBeFalse()
        ->and($provider->verify($secret, '12345'))->toBeFalse();
});

test('a recovery code works once', function () {
    $admin = enrol($this->staffUser([$this->cmsRole('Super Admin', true)], $this->branch));
    $recovery = $admin->recoveryCodes()[0];

    $this->actingAs($admin)->post('/mfa/challenge', ['recovery_code' => $recovery])->assertSessionHasNoErrors();
    $this->get('/dashboard')->assertOk();
    expect($admin->fresh()->recoveryCodes())->not->toContain($recovery)->toHaveCount(8)
        ->and(audited('identity.mfa.recovery_code_used', $admin))->toBeTrue()
        ->and(DB::table('sec_audit_logs')->where('action', 'identity.mfa.recovery_code_used')->value('new_values'))->not->toContain($recovery);

    $this->flushSession();
    $this->actingAs($admin)->from('/mfa/challenge')->post('/mfa/challenge', ['recovery_code' => $recovery])->assertSessionHasErrors('recovery_code');
});

test('challenge attempts are rate-limited per user', function () {
    $admin = enrol($this->staffUser([$this->cmsRole('Super Admin', true)], $this->branch));

    foreach (range(1, 5) as $attempt) {
        $this->actingAs($admin)->from('/mfa/challenge')->post('/mfa/challenge', ['code' => '000001'])->assertSessionHasErrors('code');
    }
    $this->actingAs($admin)->post('/mfa/challenge', ['code' => totp($admin)])->assertStatus(429);
});

test('MFA passed by one user does not carry over to another user in the same browser', function () {
    $first = enrol($this->staffUser([$this->cmsRole('Super Admin', true)], $this->branch));
    $second = enrol($this->staffUser([$this->cmsRole('Super Admin', true)], $this->branch));

    $this->actingAs($first)->post('/mfa/challenge', ['code' => totp($first)]);
    $this->actingAs($first)->get('/dashboard')->assertOk();

    $this->actingAs($second)->get('/dashboard')->assertRedirect('/mfa/challenge');
});

test('anyone who turned on 2FA themselves is challenged too, including when coming from kmu-cms', function () {
    $user = enrol($this->staffUser([], $this->branch));

    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $user->cms_staff_id])]);
    $this->get('/dashboard')->assertRedirect('/mfa/challenge');
});

test('the password login challenge counts as passing MFA', function () {
    $admin = User::factory()->create(['email' => 'glass@kmu.test', 'password' => 'correct-horse-battery']);
    $admin->forceFill(['is_break_glass' => true])->save();
    $admin = enrol($admin);

    $this->post('/login', ['email' => 'glass@kmu.test', 'password' => 'correct-horse-battery'])->assertRedirect('/two-factor-challenge');
    $this->post('/two-factor-challenge', ['code' => totp($admin)])->assertRedirect('/dashboard');

    $this->get('/dashboard')->assertOk();
    expect(DB::table('sec_audit_logs')->where('action', 'identity.mfa.passed')->where('new_values->method', 'password_login_challenge')->exists())->toBeTrue();
});

test('password-less staff confirm their identity for security settings with a fresh code', function () {
    $user = enrol($this->staffUser([], $this->branch));

    $this->actingAs($user)->withSession(['mfa.user_id' => $user->id, 'mfa.passed_at' => time() - 20000])
        ->get('/settings/security')->assertRedirect('/mfa/challenge?confirm=1');

    $this->actingAs($user)->withSession(['mfa.user_id' => $user->id, 'mfa.passed_at' => time()])
        ->get('/settings/security')->assertOk();

    $withoutAuthenticator = $this->staffUser([], $this->branch);
    $this->actingAs($withoutAuthenticator)->get('/settings/security')->assertOk();

    $passwordUser = User::factory()->create();
    $this->actingAs($passwordUser)->get('/settings/security')->assertRedirect('/user/confirm-password');
});

test('an administrator can reset a lost authenticator; it needs a reason, ends sessions and is audited', function () {
    $admin = enrol($this->staffUser([$this->cmsRole('Super Admin', true)], $this->branch));
    DB::table('sessions')->insert(['id' => 'sess-'.bin2hex(random_bytes(8)), 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);

    $this->artisan('user:mfa-reset', ['email' => $admin->email])->assertFailed();
    expect($admin->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $this->artisan('user:mfa-reset', ['email' => $admin->email, '--reason' => 'Lost phone, ticket 42'])->assertSuccessful();

    expect($admin->fresh()->two_factor_secret)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $admin->id)->count())->toBe(0)
        ->and(DB::table('sec_audit_logs')->where('action', 'identity.mfa.reset')->value('reason'))->toBe('Lost phone, ticket 42');

    $this->actingAs($admin->fresh())->get('/dashboard')->assertRedirect('/mfa/setup');
});
