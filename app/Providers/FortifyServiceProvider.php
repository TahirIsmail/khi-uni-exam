<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

/**
 * Staff sign in to kmu-cms only; there is no login, registration, password or profile screen
 * here. Fortify is used just for its authenticator (two-factor) building blocks, behind the
 * app's own MFA screens (see routes/mfa.php), so none of its routes are registered.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }
}
