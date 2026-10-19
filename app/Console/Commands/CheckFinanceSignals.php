<?php

namespace App\Console\Commands;

use App\Services\Finance\PaymentMatcher;
use App\Services\Finance\SignalNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:check-signals')]
#[Description('Post new finance signals (budgets, subscriptions, possible duplicate or unusual payments) in Slack')]
class CheckFinanceSignals extends Command
{
    public function handle(SignalNotifier $notifier, PaymentMatcher $matcher): int
    {
        $matcher->matchIncoming();
        $count = $notifier->run();

        $this->info("{$count} new finance signals.");

        return self::SUCCESS;
    }
}
