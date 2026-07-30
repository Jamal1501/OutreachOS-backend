<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use App\Services\DataLifecycleService;
use Illuminate\Foundation\Inspiring;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('billing:reconcile-workspaces')->hourly();
Schedule::command(sprintf(
    'queue:monitor database:default --max=%d',
    (int) config('observability.health.max_pending_jobs', 500)
))->everyFiveMinutes();
Schedule::call(function (): void {
    DB::table('operational_heartbeats')->updateOrInsert(
        ['name' => 'scheduler'],
        ['last_seen_at' => now(), 'metadata' => json_encode(['source' => 'schedule']), 'created_at' => now(), 'updated_at' => now()],
    );
})->everyMinute()->name('scheduler-heartbeat');
Schedule::call(fn () => app(DataLifecycleService::class)->purgeDue())
    ->dailyAt('02:30')
    ->name('data-lifecycle-purge')
    ->withoutOverlapping();
