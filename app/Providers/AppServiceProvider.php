<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Fail loudly outside production on lazy loading, unknown attributes and
        // mass-assignment of non-fillable attributes, so these bugs never reach production.
        Model::shouldBeStrict(! app()->isProduction());

        if (app()->isProduction()) {
            URL::forceHttps();
        }

        // NIST SP 800-63B: length and breached-password checks, no composition rules.
        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->max(128)
                ->uncompromised()
            : null,
        );
    }
}
