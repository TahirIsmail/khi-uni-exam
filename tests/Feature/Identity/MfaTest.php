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
    $this->cmsMfa(true);
    RateLimiter::clear('mfa');
});

function enrol(User $user): User
{
    app(EnableTwoFactorAuthentication::class)($user, true);
    // The previous code (one 30-second step back, still accepted), so the current one stays unused for the test.
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

test('two-factor authentication is off unless turned on in kmu-cms', function () {
    DB::table(config('database.cms_source_database').'.sch_settings')->delete();
    $user = $this->staffUser([], $this->branch);
    $this->actingAs($user)->get('/dashboard')->assertOk();

    $this->cmsMfa(false);
    $enrolled = enrol($this->staffUser([], $this->branch));
    $this->actingAs($enrolled)->get('/dashboard')->assertOk();
});

test('when turned on, every staff member must set up an authenticator before anything else', function () {
    $user = $this->staffUser([], $this->branch);

    $this->actingAs($user)->get('/dashboard')->assertRedirect('/mfa/setup');
    $this->actingAs($user)->get('/dashboard', ['X-Inertia' => 'true'])->assertRedirect('/mfa/setup');
    $this->actingAs($user)->get('/dashboard', ['Accept' => 'application/json'])->assertForbidden()->assertJsonPath('redirect', route('mfa.setup'));
    $this->actingAs($user)->post('/logout')->assertRedirect();
});

test('setting up an authenticator: wrong code refused, right code enrols, recovery codes shown once', function () {
    $user = $this->staffUser([], $this->branch);
    $this->actingAs($user)->get('/dashboard');

    $this->actingAs($user)->get('/mfa/setup')->assertInertia(fn ($page) => $page->component('auth/MfaSetup')->where('started', false)->where('required', true));
    $this->actingAs($user)->post('/mfa/setup')->assertRedirect('/mfa/setup');
    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull()->and($user->two_factor_confirmed_at)->toBeNull();

    $this->actingAs($user)->get('/mfa/setup')->assertInertia(fn ($page) => $page->where('started', true)
        ->where('setupKey', decrypt($user->two_factor_secret))
        ->where('qrCodeSvg', fn ($svg) => str_starts_with((string) $svg, '<svg')));

    $wrong = totp($user) === '000000' ? '111111' : '000000';
    $this->actingAs($user)->from('/mfa/setup')->post('/mfa/setup/confirm', ['code' => $wrong])->assertSessionHasErrors('code');
    $this->actingAs($user)->from('/mfa/setup')->post('/mfa/setup/confirm', ['code' => '12ab56'])->assertSessionHasErrors('code');
    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();

    $this->actingAs($user)->post('/mfa/setup/confirm', ['code' => totp($user)])->assertRedirect('/mfa/recovery-codes');
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and(audited('identity.mfa.enrolled', $user))->toBeTrue();

    $this->actingAs($user)->get('/mfa/recovery-codes')->assertInertia(fn ($page) => $page->component('auth/MfaRecoveryCodes')
        ->where('codes', fn ($codes) => count($codes) === 8)
        ->where('continueUrl', url('/dashboard')));
    $this->actingAs($user)->get('/mfa/recovery-codes')->assertRedirect();

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

test('an authenticator that is already set up cannot be replaced from a session', function () {
    $user = enrol($this->staffUser([], $this->branch));
    $secret = $user->two_factor_secret;

    $this->actingAs($user)->get('/mfa/setup')->assertRedirect('/mfa/challenge');
    $this->actingAs($user)->post('/mfa/setup')->assertForbidden();
    $this->actingAs($user)->post('/mfa/setup/confirm', ['code' => totp($user)])->assertForbidden();

    expect($user->fresh()->two_factor_secret)->toBe($secret);
});

test('an enrolled user coming from kmu-cms passes the challenge once per session and returns where they were going', function () {
    $user = enrol($this->staffUser([], $this->branch));

    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $user->cms_staff_id, 'redirect' => '/dashboard?tab=mine'])])->assertRedirect('/dashboard?tab=mine');
    $this->get('/dashboard?tab=mine')->assertRedirect('/mfa/challenge');
    $this->get('/mfa/challenge')->assertInertia(fn ($page) => $page->component('auth/MfaChallenge'));

    $this->from('/mfa/challenge')->post('/mfa/challenge', ['code' => totp($user) === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
    expect(audited('identity.mfa.failed', $user))->toBeTrue();

    $this->post('/mfa/challenge', ['code' => totp($user)])->assertRedirect('/dashboard?tab=mine');
    $this->get('/dashboard')->assertOk();
    expect(audited('identity.mfa.passed', $user))->toBeTrue();
});

test('an authenticator code cannot be used twice', function () {
    $user = enrol($this->staffUser([], $this->branch));
    $code = totp($user, 1);

    $this->actingAs($user)->get('/dashboard')->assertRedirect('/mfa/challenge');
    $this->post('/mfa/challenge', ['code' => $code])->assertSessionHasNoErrors();

    $this->flushSession();
    $this->actingAs($user)->from('/mfa/challenge')->post('/mfa/challenge', ['code' => $code])->assertSessionHasErrors('code');
});

test('a code from the next 30-second step also works only once, and an older code is refused after a newer one', function () {
    $user = enrol($this->staffUser([], $this->branch));
    $provider = app(TwoFactorAuthenticationProvider::class);
    $secret = decrypt($user->two_factor_secret);

    expect($provider->verify($secret, totp($user, 1)))->toBeTrue()
        ->and($provider->verify($secret, totp($user, 1)))->toBeFalse()
        ->and($provider->verify($secret, totp($user)))->toBeFalse()
        ->and($provider->verify($secret, '12345'))->toBeFalse();
});

test('a recovery code works once', function () {
    $user = enrol($this->staffUser([], $this->branch));
    $recovery = $user->recoveryCodes()[0];

    $this->actingAs($user)->post('/mfa/challenge', ['recovery_code' => $recovery])->assertSessionHasNoErrors();
    $this->get('/dashboard')->assertOk();
    expect($user->fresh()->recoveryCodes())->not->toContain($recovery)->toHaveCount(8)
        ->and(audited('identity.mfa.recovery_code_used', $user))->toBeTrue()
        ->and(DB::table('sec_audit_logs')->where('action', 'identity.mfa.recovery_code_used')->value('new_values'))->not->toContain($recovery);

    $this->flushSession();
    $this->actingAs($user)->from('/mfa/challenge')->post('/mfa/challenge', ['recovery_code' => $recovery])->assertSessionHasErrors('recovery_code');
});

test('challenge attempts are rate-limited per user', function () {
    $user = enrol($this->staffUser([], $this->branch));

    foreach (range(1, 5) as $attempt) {
        $this->actingAs($user)->from('/mfa/challenge')->post('/mfa/challenge', ['code' => '000001'])->assertSessionHasErrors('code');
    }
    $this->actingAs($user)->post('/mfa/challenge', ['code' => totp($user)])->assertStatus(429);
});

test('MFA passed by one user does not carry over to another user in the same browser', function () {
    $first = enrol($this->staffUser([], $this->branch));
    $second = enrol($this->staffUser([], $this->branch));

    $this->actingAs($first)->post('/mfa/challenge', ['code' => totp($first)]);
    $this->actingAs($first)->get('/dashboard')->assertOk();

    $this->actingAs($second)->get('/dashboard')->assertRedirect('/mfa/challenge');
});

test('an administrator can reset a lost authenticator; it needs a reason, ends sessions and is audited', function () {
    $user = enrol($this->staffUser([], $this->branch));
    DB::table('sessions')->insert(['id' => 'sess-'.bin2hex(random_bytes(8)), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    $this->artisan('user:mfa-reset', ['email' => $user->email])->assertFailed();
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $this->artisan('user:mfa-reset', ['email' => $user->email, '--reason' => 'Lost phone, ticket 42'])->assertSuccessful();

    expect($user->fresh()->two_factor_secret)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sec_audit_logs')->where('action', 'identity.mfa.reset')->value('reason'))->toBe('Lost phone, ticket 42');

    $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/mfa/setup');
});
