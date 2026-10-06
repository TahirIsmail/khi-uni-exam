<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Add browser security headers to every response.
     *
     * Scripts must come from this origin or carry the per-request nonce, so an
     * injected <script> tag cannot run even if some output escaping is missed.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        // PHP adds this header itself (expose_php), so it has to be removed at the PHP level too.
        $response->headers->remove('X-Powered-By');
        if (! app()->runningInConsole()) {
            header_remove('X-Powered-By');
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Pages shown to signed-in staff may contain exam content: never let the browser or a proxy cache them.
        if ($request->user() !== null) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $scriptSources = ["'self'", "'nonce-{$nonce}'"];
        // Vue components set inline style attributes, so inline styles stay allowed; scripts do not.
        $styleSources = ["'self'", "'unsafe-inline'"];
        $connectSources = ["'self'"];

        // Allow the local Vite dev server only while it is running (never in a production build).
        if (Vite::isRunningHot()) {
            $hotOrigin = rtrim((string) file_get_contents(Vite::hotFile()));
            $scriptSources[] = $hotOrigin;
            $styleSources[] = $hotOrigin;
            $connectSources[] = $hotOrigin;
            $connectSources[] = (string) preg_replace('#^http#', 'ws', $hotOrigin);
        }

        $directives = [
            "default-src 'self'",
            'script-src '.implode(' ', $scriptSources),
            'style-src '.implode(' ', $styleSources),
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            'connect-src '.implode(' ', $connectSources),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        if (app()->isProduction()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}
