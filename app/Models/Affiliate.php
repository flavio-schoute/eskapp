<?php

namespace App\Models;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Enums\InvoiceLanguage;
use App\Enums\MollieExportStatus;
use App\Observers\AffiliateObserver;
use App\Services\MollieInvoicingCustomersExport;
use App\Support\InvoiceAddressFormat;
use Database\Factories\AffiliateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'type',
    'status',
    'login_url',
    'username',
    'password',
    'agreement',
    'payment_method',
    'invoice_company_name',
    'invoice_kvk_number',
    'invoice_vat_number',
    'invoice_address_line_1',
    'invoice_address_line_2',
    'invoice_postal_code',
    'invoice_city',
    'invoice_region',
    'invoice_country',
    'invoice_contact_person',
    'invoice_phone',
    'invoice_email',
    'invoice_language',
    'affiliate_link',
    'notes',
    'google_drive_folder_id',
    'agreement_file_name',
    'agreement_drive_file_id',
    'agreement_drive_url',
    'slack_channel_id',
    'slack_channel_name',
    'mollie_customer_id',
    'mollie_exported_at',
    'mollie_export_fingerprint',
])]
#[ObservedBy(AffiliateObserver::class)]
class Affiliate extends Model
{
    /** @use HasFactory<AffiliateFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AffiliateType::class,
            'status' => AffiliateStatus::class,
            'payment_method' => AffiliatePaymentMethod::class,
            'invoice_language' => InvoiceLanguage::class,
            'mollie_exported_at' => 'datetime',
            'password' => 'encrypted',
        ];
    }

    /**
     * Get the URL of the affiliate's Google Drive folder.
     */
    public function googleDriveFolderUrl(): ?string
    {
        if (blank($this->google_drive_folder_id)) {
            return null;
        }

        return "https://drive.google.com/drive/folders/{$this->google_drive_folder_id}";
    }

    /**
     * Get the invoice address as the lines printed on an invoice, formatted for its country.
     *
     * @return list<string>
     */
    public function invoiceAddressLines(): array
    {
        return InvoiceAddressFormat::lines([
            'company_name' => $this->invoice_company_name,
            'address_line_1' => $this->invoice_address_line_1,
            'address_line_2' => $this->invoice_address_line_2,
            'postal_code' => $this->invoice_postal_code,
            'city' => $this->invoice_city,
            'region' => $this->invoice_region,
            'country' => $this->invoice_country,
        ]);
    }

    /**
     * Get the Slack channel name for this affiliate: "<partner-name>-<type>".
     */
    public function slackChannelName(): string
    {
        return static::slackChannelNameFor($this->name, $this->type);
    }

    /**
     * Build a valid Slack channel name (lowercase, no spaces, max 80 characters) from a partner name and type.
     */
    public static function slackChannelNameFor(?string $name, AffiliateType|string|null $type): string
    {
        $type = $type instanceof AffiliateType ? $type : AffiliateType::tryFrom((string) $type);
        $typeSuffix = $type ? "-{$type->value}" : '';

        $partner = Str::of((string) $name)->slug()->limit(80 - strlen($typeSuffix), '')->rtrim('-')->toString();

        return $partner.$typeSuffix;
    }

    /**
     * Get a link that opens the affiliate's Slack channel.
     */
    public function slackChannelUrl(): ?string
    {
        if (blank($this->slack_channel_id)) {
            return null;
        }

        return "https://slack.com/app_redirect?channel={$this->slack_channel_id}";
    }

    /**
     * Get the labels of the invoice fields that still need to be filled in before this affiliate can be invoiced.
     *
     * @return list<string>
     */
    public function missingInvoiceDetails(): array
    {
        return array_keys(array_filter([
            'company name' => blank($this->invoice_company_name),
            'email address' => blank($this->invoice_email),
            'street and house number' => blank($this->invoice_address_line_1),
            'postal code' => blank($this->invoice_postal_code),
            'city' => blank($this->invoice_city),
            'country' => blank($this->invoice_country),
        ]));
    }

    public function hasCompleteInvoiceDetails(): bool
    {
        return $this->missingInvoiceDetails() === [];
    }

    /**
     * Whether this affiliate is in the latest Mollie Invoicing customer export, and whether its details changed since.
     */
    public function mollieExportStatus(): MollieExportStatus
    {
        if (blank($this->mollie_export_fingerprint)) {
            return MollieExportStatus::NotExported;
        }

        return hash_equals($this->mollie_export_fingerprint, app(MollieInvoicingCustomersExport::class)->fingerprint($this))
            ? MollieExportStatus::Exported
            : MollieExportStatus::ChangedSinceExport;
    }

    /**
     * @return HasMany<AffiliateInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(AffiliateInvoice::class);
    }

    /**
     * @return HasOne<AffiliateInvoice, $this>
     */
    public function latestInvoice(): HasOne
    {
        return $this->hasOne(AffiliateInvoice::class)->latestOfMany();
    }
}
