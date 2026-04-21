<?php

namespace App\Providers;

use App\Contracts\DiscoveryProvider;
use App\Contracts\EnrichmentProvider;
use App\Services\Providers\ApifyDiscoveryProvider;
use App\Services\Providers\ApifyEnrichmentProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DiscoveryProvider::class, function ($app) {
            return match ((string) config('outreach.providers.discovery', 'apify')) {
                'apify' => $app->make(ApifyDiscoveryProvider::class),
                default => throw new InvalidArgumentException('Unsupported discovery provider binding'),
            };
        });

        $this->app->bind(EnrichmentProvider::class, function ($app) {
            return match ((string) config('outreach.providers.enrichment', 'apify')) {
                'apify' => $app->make(ApifyEnrichmentProvider::class),
                default => throw new InvalidArgumentException('Unsupported enrichment provider binding'),
            };
        });
    }

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($this->rateLimitKey($request));
        });

        RateLimiter::for('expensive', function (Request $request) {
            return Limit::perMinute(12)->by($this->rateLimitKey($request));
        });

        RateLimiter::for('avatar', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
    }

    private function rateLimitKey(Request $request): string
    {
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $userId = (string) ($request->attributes->get('supabase_user_id') ?: $request->attributes->get('auth_user_id'));

        return implode('|', array_filter([$workspaceId, $userId])) ?: $request->ip();
    }
}
