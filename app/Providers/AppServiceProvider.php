<?php

namespace App\Providers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\ActiveBranch;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\Permissions;
use App\Domain\Identity\Mfa\RecordTwoFactorEvents;
use App\Domain\Identity\Mfa\SingleUseTotpProvider;
use App\Domain\Identity\Sso\CmsTicketVerifier;
use App\Models\User;
use App\Support\Cms\CmsSettings;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use PragmaRX\Google2FA\Google2FA;

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

        // Authenticator codes are single-use everywhere (see SingleUseTotpProvider).
        $this->app->singleton(TwoFactorAuthenticationProvider::class, fn ($app): SingleUseTotpProvider => new SingleUseTotpProvider(
            $app->make(Google2FA::class),
            $app->make(Repository::class),
        ));

        // One instance per request, so permission lookups and the request context are not shared between requests.
        $this->app->scoped(AccessControl::class);
        $this->app->scoped(CmsSettings::class);
        $this->app->scoped(ActiveBranch::class);
        $this->app->scoped(AuditLogger::class, fn (): AuditLogger => new AuditLogger);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        RateLimiter::for('cms-sso', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));

        // Authenticator and recovery codes: per user, so one account cannot be brute-forced from many IPs.
        RateLimiter::for('mfa', fn (Request $request) => Limit::perMinute(5)->by('mfa|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        Event::listen([
            ValidTwoFactorAuthenticationCodeProvided::class,
            TwoFactorAuthenticationConfirmed::class,
            TwoFactorAuthenticationFailed::class,
            TwoFactorAuthenticationDisabled::class,
            RecoveryCodesGenerated::class,
            RecoveryCodeReplaced::class,
        ], RecordTwoFactorEvents::class);

        // Catalogue permissions (e.g. Gate::allows('qbank.question.view')) are answered by AccessControl.
        // Any other ability falls through to policies.
        Gate::before(function (User $user, string $ability): ?bool {
            return Permissions::exists($ability) ? app(AccessControl::class)->has($user, $ability) : null;
        });

        // "Create Exam" opens for anybody who may see examinations or blueprints (not a CMS checkbox of its own).
        Gate::define('exam.access', fn (User $user): bool => app(AccessControl::class)->has($user, 'exam.view')
            || app(AccessControl::class)->has($user, 'exam.blueprint.view'));
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
