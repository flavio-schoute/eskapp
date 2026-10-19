<?php

namespace App\Services\Finance;

use App\Enums\Integration;
use App\Filament\Pages\Finance\Signals as SignalsPage;
use App\Models\Finance\Alert;
use App\Services\IntegrationErrorLogger;
use App\Services\Slack;
use App\Support\FinanceAlerts;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Finds the current signals and posts the ones not sent before in one Slack message. Each signal is stored as an
 * alert with a unique key, so it is only sent once.
 */
class SignalNotifier
{
    public function __construct(private Slack $slack, private IntegrationErrorLogger $errorLogger) {}

    /**
     * @return list<array{key: string, type: string, message: string}>
     */
    public static function current(?Carbon $today = null): array
    {
        $today ??= now();
        $settings = FinanceAlerts::settings();
        $signals = [];

        if ($settings['budgets']) {
            $month = $today->format('Y-m');

            foreach ((new FinanceReport)->budgets($month) as $budget) {
                if ($budget['spent'] > $budget['budget']) {
                    $signals[] = ['key' => "budget:{$budget['category']->getKey()}:{$month}", 'type' => 'budget', 'message' => FinanceAlerts::render('budget', ['{categorie}' => $budget['label'], '{besteed}' => Money::format($budget['spent'], decimals: false), '{budget}' => Money::format($budget['budget'], decimals: false)])];
                }
            }
        }

        if ($settings['subscriptions']) {
            foreach (Signals::subscriptions($today) as $subscription) {
                if ($subscription['isNew']) {
                    $signals[] = ['key' => "subscription-new:{$subscription['key']}", 'type' => 'subscription_new', 'message' => FinanceAlerts::render('subscription_new', ['{partij}' => $subscription['name'], '{bedrag}' => Money::format($subscription['typical'])])];
                }

                if ($subscription['priceUp']) {
                    $signals[] = ['key' => "subscription-price:{$subscription['transactionId']}", 'type' => 'subscription_price', 'message' => FinanceAlerts::render('subscription_price', ['{partij}' => $subscription['name'], '{oud}' => Money::format($subscription['previous']), '{nieuw}' => Money::format($subscription['last'])])];
                }
            }
        }

        if ($settings['payments']) {
            foreach (Signals::bankPayments(today: $today) as $payment) {
                $signals[] = ['key' => $payment['key'], 'type' => $payment['type'], 'message' => $payment['message']];
            }
        }

        if ($settings['anomalies']) {
            foreach (Signals::anomalies(today: $today) as $anomaly) {
                $signals[] = ['key' => $anomaly['key'], 'type' => $anomaly['type'], 'message' => $anomaly['message']];
            }
        }

        return $signals;
    }

    /**
     * Store and post the new signals; returns how many were new.
     */
    public function run(?Carbon $today = null): int
    {
        $settings = FinanceAlerts::settings();

        if (! $settings['enabled']) {
            return 0;
        }

        $new = collect(static::current($today))
            ->reject(fn (array $signal): bool => Alert::query()->where('key', $signal['key'])->exists())
            ->values();

        if ($new->isEmpty()) {
            return 0;
        }

        $sent = false;

        if ($this->slack->isConfigured() && filled($settings['channel'])) {
            try {
                $this->slack->postMessage($settings['channel'], FinanceAlerts::message($new->pluck('message')->all(), SignalsPage::getUrl()));
                $sent = true;
            } catch (Throwable $exception) {
                // Not stored, so they are tried again next time.
                $this->errorLogger->record(Integration::Slack, 'Post finance signals', $exception);

                return 0;
            }
        }

        foreach ($new as $signal) {
            Alert::create([...$signal, 'notified_at' => $sent ? now() : null]);
        }

        return $new->count();
    }
}
