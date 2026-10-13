<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Business scheduled jobs (billing runs, report generation, call-log
| reconciliation, etc.) will be registered here in later phases. The
| scheduler is intentionally empty in this foundational phase.
|
| Run the scheduler in production with:
|   * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
|
*/
