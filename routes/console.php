<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ZR-03 — keep order statuses in sync with ZR Express delivery states. The
// command short-circuits when the integration is disabled, so this is a
// no-op until the admin connects the account. Requires `schedule:run` to be
// driven by cron (or a Railway cron service) in production.
Schedule::command('zr:sync-statuses')
    ->everyTenMinutes()
    ->withoutOverlapping();
