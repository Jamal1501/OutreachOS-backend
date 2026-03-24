<?php

namespace App\Providers;

use App\Contracts\DiscoveryProvider;
use App\Contracts\EnrichmentProvider;
use App\Services\Providers\ApifyDiscoveryProvider;
use App\Services\Providers\ApifyEnrichmentProvider;
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
        //
    }
}
