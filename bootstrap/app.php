<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureDeliverySessionActive;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireMfa;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')->group(__DIR__.'/../routes/sso.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->prepend(AssignRequestId::class);

        // The CMS sign-on ticket (signed, 60 s, single use) replaces the CSRF token on this route only.
        $middleware->validateCsrfTokens(except: ['sso/cms']);

        // There is no login screen here: staff guests sign in to kmu-cms and come back through
        // "Question Bank & Exams". A candidate sitting an exam signs in on the exam's own page instead.
        $middleware->redirectGuestsTo(function (Request $request): string {
            $name = $request->route()?->getName();
            if (is_string($name) && str_starts_with($name, 'sit.')) {
                $exam = $request->route('exam');

                return route('sit.login', $exam instanceof Model ? $exam->getKey() : $exam);
            }

            return rtrim((string) config('services.kmu_cms.url'), '/').'/site/login';
        });

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            EnsureUserIsActive::class,
            RequireMfa::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias(['delivery.session' => EnsureDeliverySessionActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
