<?php

use App\Domain\Identity\Sso\CmsTicketVerifier;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Session;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithCms;

uses(InteractsWithCms::class);

beforeEach(function () {
    $this->shareCmsConnection();
});

function refused($response): void
{
    $response->assertForbidden()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/SsoFailed')
        ->where('cmsUrl', 'https://cms.example.test'));
}

test('a valid ticket signs the staff member in and creates a linked local user', function () {
    $staffId = $this->cmsStaff(['name' => 'Ayesha', 'surname' => 'Khan', 'email' => 'Ayesha.Khan@KMU.test']);

    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $staffId])])
        ->assertRedirect('/dashboard');

    $user = User::query()->where('cms_staff_id', $staffId)->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Ayesha Khan')
        ->and($user->email)->toBe('ayesha.khan@kmu.test')
        ->and($user->password)->toBeNull()
        ->and($user->last_login_at)->not->toBeNull();
});

test('the Intake (Academic Session) selected in kmu-cms is kept for filing questions', function () {
    $staffId = $this->cmsStaff();

    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $staffId, 'intake' => 34])])->assertRedirect('/dashboard');
    expect(session('cms_intake_id'))->toBe(34);

    // A ticket without one (an older kmu-cms) leaves the newest session of the campus to be used.
    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $staffId])])->assertRedirect('/dashboard');
    expect(session()->has('cms_intake_id'))->toBeFalse();
});

test('a later visit reuses the same user and syncs name and email from the CMS', function () {
    $staffId = $this->cmsStaff(['name' => 'Ayesha', 'email' => 'ayesha@kmu.test']);
    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $staffId])]);
    auth()->logout();

    DB::table(config('database.cms_source_database').'.staff')->where('id', $staffId)->update(['name' => 'Aisha', 'email' => 'aisha@kmu.test']);
    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $staffId])])->assertRedirect('/dashboard');

    expect(User::query()->where('cms_staff_id', $staffId)->count())->toBe(1)
        ->and(User::query()->where('cms_staff_id', $staffId)->value('email'))->toBe('aisha@kmu.test');
});

test('an internal redirect path in the ticket is followed', function () {
    $staffId = $this->cmsStaff();

    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $staffId, 'redirect' => '/settings/profile?tab=1'])])
        ->assertRedirect('/settings/profile?tab=1');
});

test('redirects to other sites are replaced by the dashboard', function (string $redirect) {
    $staffId = $this->cmsStaff();

    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $staffId, 'redirect' => $redirect])])
        ->assertRedirect('/dashboard');
})->with(['//evil.example', 'https://evil.example/', '/\\evil.example', 'javascript:alert(1)', "/dashboard\r\nSet-Cookie: x=1", 'dashboard']);

test('the session id changes on sign-in', function () {
    $staffId = $this->cmsStaff();
    $this->get('/login');
    $before = Session::getId();

    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $staffId])]);

    expect(Session::getId())->not->toBe($before);
});

test('a ticket with a changed payload is refused', function () {
    $staffId = $this->cmsStaff();
    $other = $this->cmsStaff();
    [$payload, $signature] = explode('.', $this->cmsTicket(['sub' => $staffId]));
    $forged = CmsTicketVerifier::base64UrlEncode(str_replace((string) $staffId, (string) $other, (string) base64_decode(strtr($payload, '-_', '+/'))));

    refused($this->post('/sso/cms', ['ticket' => $forged.'.'.$signature]));
    $this->assertGuest();
});

test('a ticket signed with another secret is refused', function () {
    $staffId = $this->cmsStaff();

    refused($this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $staffId], base64_encode(random_bytes(32)))]));
    $this->assertGuest();
});

test('tickets with bad claims are refused', function (array $claims) {
    $staffId = $this->cmsStaff();

    refused($this->post('/sso/cms', ['ticket' => $this->cmsTicket(array_merge(['sub' => $staffId], $claims))]));
    $this->assertGuest();
})->with([
    'expired' => [['iat' => time() - 200, 'exp' => time() - 140]],
    'issued in the future' => [['iat' => time() + 120, 'exp' => time() + 180]],
    'lifetime over 60 seconds' => [['iat' => time(), 'exp' => time() + 3600]],
    'wrong audience' => [['aud' => 'another-app']],
    'wrong issuer' => [['iss' => 'someone-else']],
    'short ticket id' => [['jti' => 'abc']],
    'string staff id' => [['sub' => '1']],
    'zero staff id' => [['sub' => 0]],
]);

test('a ticket can be used only once', function () {
    $staffId = $this->cmsStaff();
    $ticket = $this->cmsTicket(['sub' => $staffId]);

    $this->post('/sso/cms', ['ticket' => $ticket])->assertRedirect('/dashboard');
    auth()->logout();

    refused($this->post('/sso/cms', ['ticket' => $ticket]));
    $this->assertGuest();
});

test('a refused ticket stays used even after the reason is fixed', function () {
    $staffId = $this->cmsStaff(['is_active' => 0]);
    $ticket = $this->cmsTicket(['sub' => $staffId]);

    refused($this->post('/sso/cms', ['ticket' => $ticket]));
    DB::table(config('database.cms_source_database').'.staff')->where('id', $staffId)->update(['is_active' => 1]);

    refused($this->post('/sso/cms', ['ticket' => $ticket]));
    $this->assertGuest();
});

test('unknown or inactive CMS staff are refused', function () {
    refused($this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => 987654])]));

    $inactive = $this->cmsStaff(['is_active' => 0]);
    refused($this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $inactive])]));

    $this->assertGuest();
    expect(User::query()->whereIn('cms_staff_id', [987654, $inactive])->exists())->toBeFalse();
});

test('a deactivated local user is refused', function () {
    $staffId = $this->cmsStaff();
    User::factory()->create()->forceFill(['cms_staff_id' => $staffId, 'is_active' => false])->save();

    refused($this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $staffId])]));
    $this->assertGuest();
});

test('an existing local account with the same email is never taken over', function () {
    $local = User::factory()->create(['email' => 'breakglass@kmu.test']);
    $staffId = $this->cmsStaff(['email' => 'BreakGlass@kmu.test']);

    refused($this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $staffId])]));

    $this->assertGuest();
    expect($local->fresh()->cms_staff_id)->toBeNull();
});

test('missing or malformed tickets are refused', function (mixed $ticket) {
    refused($this->post('/sso/cms', ['ticket' => $ticket]));
    $this->assertGuest();
})->with([null, '', 'not-a-ticket', 'a.b.c', str_repeat('a', 3000).'.b', ['array']]);

test('the endpoint does not accept GET', function () {
    $this->get('/sso/cms')->assertMethodNotAllowed();
});

test('the endpoint is rate limited per IP', function () {
    foreach (range(1, 20) as $attempt) {
        $this->post('/sso/cms', ['ticket' => 'x'])->assertForbidden();
    }

    $this->post('/sso/cms', ['ticket' => 'x'])->assertStatus(429);
});

test('without a configured secret every ticket is refused', function () {
    $staffId = $this->cmsStaff();
    $ticket = $this->cmsTicket(['sub' => $staffId]);
    config(['services.kmu_cms.sso_secret' => '']);

    refused($this->post('/sso/cms', ['ticket' => $ticket]));
    $this->assertGuest();
});

test('the refusal page does not reveal why the ticket was refused', function () {
    $response = $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => 987654])]);

    $response->assertDontSee('unknown_staff')->assertDontSee('987654');
});

test('the sso route is the only csrf exemption', function () {
    $except = (new ReflectionClass(ValidateCsrfToken::class))->getStaticPropertyValue('neverVerify');

    expect($except)->toBe(['sso/cms']);
});
