<?php

namespace App\Providers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\Permissions;
use App\Domain\Identity\Sso\CmsTicketVerifier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->bind(CmsTicketVerifier::class, fn (): CmsTicketVerifier => new CmsTicketVerifier(
            (string) config('services.kmu_cms.sso_secret'),
            (int) config('services.kmu_cms.ticket_ttl_seconds'),
            (int) config('services.kmu_cms.clock_skew_seconds'),
        ));

        // One instance per request, so permission lookups and the request context are not shared between requests.
        $this->app->scoped(AccessControl::class);
        $this->app->scoped(AuditLogger::class, fn (): AuditLogger => new AuditLogger);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        RateLimiter::for('cms-sso', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));

        // Catalogue permissions (e.g. Gate::allows('qbank.question.view')) are answered by AccessControl.
        // Any other ability falls through to policies.
        Gate::before(function (User $user, string $ability): ?bool {
            return Permissions::exists($ability) ? app(AccessControl::class)->has($user, $ability) : null;
        });
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
