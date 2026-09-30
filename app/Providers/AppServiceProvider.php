<?php

namespace App\Providers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\ActiveBranch;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\Permissions;
use App\Domain\Identity\Mfa\RecordTwoFactorEvents;
use App\Domain\Identity\Mfa\SingleUseTotpProvider;
use App\Domain\Identity\Sso\CmsTicketVerifier;
use App\Domain\Results\Support\GradeScales;
use App\Models\User;
use App\Support\Cms\CmsSettings;
use App\Support\Cms\CmsTeaching;
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
        $this->app->scoped(CmsTeaching::class);
        $this->app->scoped(GradeScales::class);
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

        $this->configureSitRateLimits();

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

        // "Conduct Exam" opens for anybody who works with candidates, checking in or monitoring delivery.
        Gate::define('exam.conduct.access', fn (User $user): bool => app(AccessControl::class)->has($user, 'candidate.view')
            || app(AccessControl::class)->has($user, 'candidate.checkin')
            || app(AccessControl::class)->has($user, 'delivery.monitor'));
    }

    /**
     * Sitting an exam: each route counts on its own, and per candidate. A plain `throttle:10,1` keys
     * on the signed-in user alone, so a candidate's autosaves would use up the allowance of their
     * own submit; and before sign-in it keys on the IP, which a whole exam hall shares.
     */
    protected function configureSitRateLimits(): void
    {
        // PIN guessing is limited per candidate number; the per-IP limit is only a ceiling, high
        // enough for a hall behind one address signing in together.
        RateLimiter::for('sit-login', fn (Request $request) => [
            Limit::perMinute(10)->by('sit-login|'.$request->route()?->originalParameter('exam').'|'.mb_strtolower(trim((string) $request->input('candidate_no')))),
            Limit::perMinute(600)->by('sit-login-ip|'.$request->ip()),
        ]);

        foreach (['sit-heartbeat' => 60, 'sit-answer' => 120, 'sit-submit' => 10, 'sit-device' => 30, 'sit-proctor-event' => 120] as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)
                ->by($name.'|'.($request->user('candidate')?->getAuthIdentifier() ?? $request->ip())));
        }
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
