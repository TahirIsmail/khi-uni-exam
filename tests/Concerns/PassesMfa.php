<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * For tests about something other than MFA: signing in also marks the session as having passed
 * multi-factor authentication. Enforcement itself is covered in tests/Feature/Identity/MfaTest.php.
 */
trait PassesMfa
{
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->withSession(['mfa.user_id' => $user->getAuthIdentifier(), 'mfa.passed_at' => time()]);

        return parent::actingAs($user, $guard);
    }
}
