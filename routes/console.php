<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Foundation\Inspiring;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('billing:reconcile-workspaces')->hourly();
Schedule::command(sprintf(
    'queue:monitor database:default --max=%d',
    (int) config('observability.health.max_pending_jobs', 500)
))->everyFiveMinutes();
