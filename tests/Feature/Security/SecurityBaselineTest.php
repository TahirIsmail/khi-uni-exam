<?php

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Route;

test('there are no public pages: guests are sent to the kmu-cms login', function () {
    $this->get('/')->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertRedirect(config('services.kmu_cms.url').'/site/login');
});

test('there is no login, registration, password, profile or settings screen here', function () {
    foreach (['login', 'login.store', 'register', 'password.request', 'password.reset', 'password.confirm', 'two-factor.login', 'profile.edit', 'security.edit', 'appearance.edit', 'verification.notice', 'passkey.login'] as $name) {
        expect(Route::has($name))->toBeFalse("route {$name} should not exist");
    }

    $user = User::factory()->create();
    foreach (['/login', '/register', '/forgot-password', '/settings/profile', '/settings/security', '/user/confirm-password', '/two-factor-challenge', '/admin/roles', '/admin/audit'] as $url) {
        $this->actingAs($user)->get($url)->assertNotFound();
    }
    auth()->logout();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertNotFound();
    $this->assertGuest();

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Intruder',
        'email' => 'intruder@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertDatabaseMissing('users', ['email' => 'intruder@example.com']);
});

test('unknown urls return not found', function () {
    $this->get('/admin')->assertNotFound();
    $this->get('/.env')->assertNotFound();
    $this->get('/phpinfo.php')->assertNotFound();
});

/** A page anyone can reach: the refused sign-in page. */
function publicPage($test)
{
    return $test->post('/sso/cms', ['ticket' => 'not-a-ticket']);
}

test('responses carry the security headers', function () {
    $response = publicPage($this);

    $response->assertForbidden()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/")
        ->not->toContain("script-src 'self' 'unsafe-inline'");
});

test('the inline script carries the same nonce as the policy', function () {
    $response = publicPage($this);

    preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $matches);

    expect($matches[1] ?? null)->not->toBeNull();
    $response->assertSee('<script nonce="'.$matches[1].'">', false);
});

test('pages for signed-in users are never cached', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('a tampered appearance cookie cannot inject script', function () {
    $payload = "';alert(1);//";

    $this->withUnencryptedCookie('appearance', $payload)
        ->post('/sso/cms', ['ticket' => 'not-a-ticket'])
        ->assertForbidden()
        ->assertDontSee($payload, false)
        ->assertSee("const appearance = 'system';", false);
});

test('mass assignment of unknown attributes throws outside production', function () {
    expect(fn () => new User(['name' => 'A', 'is_admin' => true]))
        ->toThrow(MassAssignmentException::class);
});
