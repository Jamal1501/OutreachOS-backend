<?php

namespace App\Providers;

use App\Contracts\DiscoveryProvider;
use App\Contracts\EnrichmentProvider;
use App\Services\ObservabilityService;
use App\Services\OperationalHeartbeatService;
use App\Services\Providers\ApifyDiscoveryProvider;
use App\Services\Providers\ApifyEnrichmentProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OperationalHeartbeatService::class);

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

        Queue::failing(function (JobFailed $event): void {
            app(ObservabilityService::class)->reportQueueFailure($event);
        });

        $this->app['events']->listen(QueueBusy::class, function (QueueBusy $event): void {
            app(ObservabilityService::class)->sendAlert('queue.busy', 'Queue depth threshold exceeded', [
                'connection' => $event->connection,
                'queue' => $event->queue,
                'size' => $event->size,
            ], 'warning');
        });

        $this->app['events']->listen(Looping::class, function (): void {
            app(OperationalHeartbeatService::class)->queueWorkerIdle();
        });
    }

    private function rateLimitKey(Request $request): string
    {
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $userId = (string) ($request->attributes->get('supabase_user_id') ?: $request->attributes->get('auth_user_id'));

        return implode('|', array_filter([$workspaceId, $userId])) ?: $request->ip();
    }
}
