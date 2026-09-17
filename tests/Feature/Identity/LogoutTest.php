<?php

use App\Domain\Identity\Sso\CmsTicketVerifier;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCms;

uses(InteractsWithCms::class);

beforeEach(function () {
    $this->shareCmsConnection();
    $this->cmsUrl = config('services.kmu_cms.url');
});

/** A logout token exactly as kmu-cms builds it (Kmu_sso_ticket::issue_logout). */
function logoutToken(array $claims = [], ?string $secret = null): string
{
    $now = time();
    $payload = CmsTicketVerifier::base64UrlEncode((string) json_encode(array_merge([
        'iss' => 'kmu-cms', 'aud' => 'kmu-assess', 'purpose' => 'logout', 'iat' => $now, 'exp' => $now + 60,
    ], $claims)));
    $key = base64_decode($secret ?? (string) config('services.kmu_cms.sso_secret'), true);

    return $payload.'.'.CmsTicketVerifier::base64UrlEncode(hash_hmac('sha256', $payload, (string) $key, true));
}

test('logging out here ends this session and logs out of kmu-cms too', function () {
    $user = $this->staffUser();

    $this->actingAs($user)->post('/logout')->assertRedirect($this->cmsUrl.'/site/logout?from=kmu-assess');
    $this->assertGuest();
    expect(DB::table('sec_audit_logs')->where('action', 'identity.logout')->value('new_values'))->toContain('kmu-assess');

    $this->actingAs($user)->post('/logout', [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $this->cmsUrl.'/site/logout?from=kmu-assess');
});

test('logout needs POST (a link or image cannot log someone out)', function () {
    $this->actingAs($this->staffUser())->get('/logout')->assertStatus(405);
    $this->assertAuthenticated();
});

test('logging out of kmu-cms ends this session and returns to the kmu-cms login page', function () {
    $user = $this->staffUser();

    $this->actingAs($user)->get('/sso/logout?token='.logoutToken())->assertRedirect($this->cmsUrl.'/site/login');
    $this->assertGuest();
    expect(DB::table('sec_audit_logs')->where('action', 'identity.logout')->value('new_values'))->toContain('kmu-cms');
});

test('a forged, expired or wrong-purpose logout token changes nothing', function () {
    $user = $this->staffUser();
    $forged = logoutToken([], base64_encode(random_bytes(32)));
    $expired = logoutToken(['iat' => time() - 300, 'exp' => time() - 240]);
    $signInTicket = $this->cmsTicket(['sub' => $user->cms_staff_id]);

    foreach (['', 'garbage', $forged, $expired, $signInTicket, logoutToken(['aud' => 'someone-else'])] as $token) {
        $this->actingAs($user)->get('/sso/logout?token='.urlencode($token))->assertForbidden();
        $this->assertAuthenticatedAs($user);
    }
});

test('a logout token can never be used to sign in', function () {
    $staffId = $this->cmsStaff();

    $this->post('/sso/cms', ['ticket' => logoutToken(['sub' => $staffId, 'jti' => bin2hex(random_bytes(32))])])->assertForbidden();
    $this->assertGuest();
});

test('the pages show a "Back to CMS" link target', function () {
    $this->actingAs($this->staffUser())->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('cmsUrl', $this->cmsUrl.'/admin/admin/dashboard'));
});
