<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if ((bool) config('security.csp.enabled', true)) {
            $headerName = (bool) config('security.csp.report_only', true)
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            $response->headers->set($headerName, $this->contentSecurityPolicy());
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $connectSrc = implode(' ', (array) config('security.csp.connect_src', ["'self'"]));
        $imgSrc = implode(' ', (array) config('security.csp.img_src', ["'self'", 'data:', 'blob:', 'https:']));
        $styleSrc = implode(' ', (array) config('security.csp.style_src', ["'self'", "'unsafe-inline'"]));
        $fontSrc = implode(' ', (array) config('security.csp.font_src', ["'self'", 'data:']));
        $reportUri = trim((string) config('security.csp.report_uri', '/api/csp-report'));

        return implode('; ', array_filter([
            "default-src 'self'",
            "script-src 'self'",
            "style-src {$styleSrc}",
            "style-src-elem {$styleSrc}",
            "img-src {$imgSrc}",
            "font-src {$fontSrc}",
            "connect-src {$connectSrc}",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self' https://checkout.stripe.com",
            $reportUri !== '' ? "report-uri {$reportUri}" : null,
        ]));
    }
}
