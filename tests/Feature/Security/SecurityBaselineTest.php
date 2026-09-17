<?php

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

test('the home page is not public and sends guests to login', function () {
    $this->get('/')->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('public registration is disabled', function () {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse();

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

test('responses carry the security headers', function () {
    $response = $this->get(route('login'));

    $response->assertOk()
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
    $response = $this->get(route('login'));

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
        ->get(route('login'))
        ->assertOk()
        ->assertDontSee($payload, false)
        ->assertSee("const appearance = 'system';", false);
});

test('mass assignment of unknown attributes throws outside production', function () {
    expect(fn () => new User(['name' => 'A', 'is_admin' => true]))
        ->toThrow(MassAssignmentException::class);
});

test('login is rate limited after five failed attempts', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertStatus(429);
});

test('sql injection in the login form does not authenticate', function () {
    User::factory()->create(['email' => 'staff@example.com']);

    $this->post(route('login.store'), [
        'email' => "staff@example.com' OR '1'='1",
        'password' => "' OR '1'='1",
    ]);

    $this->assertGuest();
});

test('passwords are hashed with the configured driver in production settings', function () {
    config(['hashing.driver' => 'argon2id']);

    expect(Hash::driver('argon2id')->make('secret-password'))->toStartWith('$argon2id$');
});
