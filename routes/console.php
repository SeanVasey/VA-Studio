<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Production runs `php artisan schedule:run` every minute. The database lock serializes publication; the short
// overlap lock is hygiene only (the 1,440-minute default could stall scheduled releases after a crashed run).
Schedule::command('vasey:publish-scheduled-site-release')->everyMinute()->withoutOverlapping(5);
