<?php

namespace App\Services;

use App\Enums\MessageTemplateType;
use App\Models\Affiliate;
use App\Models\AffiliateInvoice;
use App\Models\MessageTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Posts the "invoice sent" message, with the invoice PDF attached, in the affiliate's Slack channel.
 */
class SlackInvoiceMessages
{
    public function __construct(public Slack $slack) {}

    /**
     * Whether a message can be posted for this affiliate: Slack is set up and the affiliate has a channel.
     */
    public function canPost(Affiliate $affiliate): bool
    {
        return $this->slack->isConfigured() && filled($affiliate->slack_channel_id);
    }

    /**
     * The message text, from the default Slack invoice template in the contact's language.
     */
    public function message(Affiliate $affiliate, Carbon $period, string $invoiceNumber, string $amount, string $email): ?string
    {
        $template = MessageTemplate::defaultFor(MessageTemplateType::SlackInvoice, $affiliate->slackContactLanguage());

        if (! $template) {
            return null;
        }

        $contactName = $affiliate->slack_contact_name ?: ($affiliate->invoice_contact_person ?: (string) $affiliate->invoice_company_name);

        return $template->renderFor($affiliate, $period, [
            '{mention}' => filled($affiliate->slack_contact_member_id) ? "<@{$affiliate->slack_contact_member_id}>" : $contactName,
            '{contact_person}' => $contactName,
            '{invoice_number}' => $invoiceNumber,
            '{amount}' => $amount,
            '{email}' => $email,
        ])['body'];
    }

    /**
     * Post the message for an invoice that was just sent, with its PDF when it can be downloaded.
     */
    public function post(Affiliate $affiliate, AffiliateInvoice $invoice): void
    {
        if (! $this->canPost($affiliate)) {
            throw new RuntimeException('Slack is not configured or the affiliate has no Slack channel.');
        }

        $message = $this->message(
            $affiliate,
            $invoice->period,
            (string) ($invoice->invoice_number ?? ''),
            self::formatAmount((float) ($invoice->total_amount ?? $invoice->amount)),
            $invoice->sent_to,
        ) ?? throw new RuntimeException('There is no Slack invoice message template.');

        $pdf = $this->downloadPdf($invoice);

        if ($pdf !== null) {
            $this->slack->uploadFile($affiliate->slack_channel_id, $this->pdfFilename($invoice), $pdf, $message);
        } else {
            $this->slack->postMessage(
                $affiliate->slack_channel_id,
                $message.(filled($invoice->pdf_url) ? "\n<{$invoice->pdf_url}|Download the invoice (PDF)>" : ''),
            );
        }

        $invoice->update(['slack_posted_at' => now()]);
    }

    public static function formatAmount(float $amount): string
    {
        return '€ '.number_format($amount, 2, ',', '.');
    }

    /**
     * Download the invoice PDF from Mollie. The Mollie key is only sent to Mollie's own API domain.
     */
    private function downloadPdf(AffiliateInvoice $invoice): ?string
    {
        if (blank($invoice->pdf_url)) {
            return null;
        }

        $request = Http::timeout(30);

        if (parse_url($invoice->pdf_url, PHP_URL_HOST) === 'api.mollie.com') {
            $request = $request->withToken((string) config('mollie.key'));
        }

        $response = $request->get($invoice->pdf_url);

        return $response->successful() && str_starts_with($response->body(), '%PDF') ? $response->body() : null;
    }

    private function pdfFilename(AffiliateInvoice $invoice): string
    {
        return 'Invoice '.($invoice->invoice_number ?: $invoice->mollie_sales_invoice_id).'.pdf';
    }
}
