<?php

namespace App\Models;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Observers\AffiliateObserver;
use App\Support\InvoiceAddressFormat;
use Database\Factories\AffiliateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    'invoice_address_line_1',
    'invoice_address_line_2',
    'invoice_postal_code',
    'invoice_city',
    'invoice_region',
    'invoice_country',
    'invoice_contact_person',
    'invoice_phone',
    'invoice_email',
    'affiliate_link',
    'notes',
    'google_drive_folder_id',
    'agreement_file_name',
    'agreement_drive_file_id',
    'agreement_drive_url',
    'slack_channel_id',
    'slack_channel_name',
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
}
