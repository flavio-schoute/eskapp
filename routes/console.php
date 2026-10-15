<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('invoices:sync-payment-status')->hourly()->withoutOverlapping();
Schedule::command('contracts:sync-status')->hourly()->withoutOverlapping();
// Checks every 5 minutes whether the reminder is due; the day, time and message are set under System → Slack settings.
Schedule::command('invoices:monthly-reminder')->everyFiveMinutes()->withoutOverlapping();
// Partners get the reminder during office hours, not at night.
Schedule::command('contracts:send-reminders')->hourlyAt(15)->between('9:00', '17:00')->timezone('Europe/Amsterdam')->withoutOverlapping();
