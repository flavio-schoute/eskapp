<?php

namespace App\Jobs;

use App\Enums\Integration;
use App\Enums\MessageTemplateType;
use App\Models\Affiliate;
use App\Models\AffiliateInvoice;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\IntegrationErrorLogger;
use App\Services\MollieSalesInvoices;
use App\Services\SlackInvoiceMessages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Creates and emails one affiliate invoice through Mollie in the background, used by "Monthly invoices".
 * Not retried: a retry after a timeout could create the invoice twice.
 */
class IssueAffiliateInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $affiliateId,
        public string $period,
        public string $amount,
        public string $description,
        public string $paymentTerm,
        public bool $postToSlack = true,
        public ?int $creatorId = null,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("affiliate-invoice-{$this->affiliateId}"))->releaseAfter(10)->expireAfter(300)];
    }

    public function handle(MollieSalesInvoices $invoices, SlackInvoiceMessages $slackMessages, IntegrationErrorLogger $errorLogger): void
    {
        $affiliate = Affiliate::find($this->affiliateId);
        $period = Carbon::parse($this->period);

        if (! $affiliate || AffiliateInvoice::invoicedFor($affiliate, $period)) {
            return;
        }

        try {
            if (! $affiliate->hasCompleteInvoiceDetails()) {
                throw new RuntimeException('Add the '.implode(', ', $affiliate->missingInvoiceDetails()).' to the invoice details first.');
            }

            $email = MessageTemplate::defaultFor(MessageTemplateType::MollieInvoice, $affiliate->invoiceLanguage())?->renderFor($affiliate, $period)
                ?? throw new RuntimeException('There is no invoice email template.');

            $invoice = $invoices->issue(
                $affiliate,
                $period,
                $this->amount,
                $this->description,
                $this->paymentTerm,
                $email['subject'],
                $email['body'],
                User::find($this->creatorId),
            );
        } catch (Throwable $exception) {
            $errorLogger->record(Integration::Mollie, 'Generate invoice', $exception, $affiliate);

            return;
        }

        if ($this->postToSlack && $slackMessages->canPost($affiliate)) {
            try {
                $slackMessages->post($affiliate, $invoice);
            } catch (Throwable $exception) {
                $errorLogger->record(Integration::Slack, 'Post invoice message', $exception, $affiliate);
            }
        }
    }
}
