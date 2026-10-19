<?php

use App\Jobs\Finance\SyncFinanceOrders;
use App\Jobs\SyncCustomers;
use App\Jobs\SyncProducts;
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
Schedule::command('debtors:sync')->everyThirtyMinutes()->withoutOverlapping();
Schedule::job(new SyncCustomers)->dailyAt('04:00')->timezone('Europe/Amsterdam');
Schedule::job(new SyncProducts)->dailyAt('04:30')->timezone('Europe/Amsterdam');
Schedule::job(new SyncFinanceOrders(days: 120))->hourlyAt(20);
Schedule::job(new SyncFinanceOrders(days: null))->dailyAt('03:30')->timezone('Europe/Amsterdam');
// Budgets, subscriptions and possible duplicate or unusual payments; set under System → Slack settings.
Schedule::command('finance:check-signals')->dailyAt('08:45')->timezone('Europe/Amsterdam')->withoutOverlapping();
