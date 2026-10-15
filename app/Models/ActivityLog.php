<?php

namespace App\Models;

use BackedEnum;
use Database\Factories\ActivityLogFactory;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Who did what and when with an affiliate: changes, invoices and contracts.
 */
#[Fillable([
    'affiliate_id',
    'affiliate_name',
    'user_id',
    'user_name',
    'event',
    'description',
    'changes',
    'created_at',
])]
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Attributes that are never shown with their value.
     *
     * @var list<string>
     */
    public const SecretAttributes = ['password'];

    /**
     * Attributes that are not worth logging.
     *
     * @var list<string>
     */
    public const IgnoredAttributes = ['created_at', 'updated_at'];

    /**
     * @var array<string, string>
     */
    public const AttributeLabels = [
        'name' => 'Partner name',
        'login_url' => 'Login URL',
        'payment_method' => 'Payment',
        'invoice_company_name' => 'Company name',
        'invoice_kvk_number' => 'KvK number',
        'invoice_vat_number' => 'VAT number',
        'invoice_address_line_1' => 'Street and house number',
        'invoice_address_line_2' => 'Address line 2',
        'invoice_postal_code' => 'Postal code',
        'invoice_city' => 'City',
        'invoice_region' => 'Region',
        'invoice_country' => 'Country',
        'invoice_contact_person' => 'Contact person',
        'invoice_phone' => 'Phone number',
        'invoice_email' => 'Invoice email',
        'invoice_language' => 'Invoice language',
        'affiliate_link' => 'Affiliate link',
        'google_drive_folder_id' => 'Google Drive folder',
        'agreement_file_name' => 'Agreement document',
        'agreement_drive_file_id' => 'Agreement file in Drive',
        'agreement_drive_url' => 'Agreement link',
        'slack_channel_id' => 'Slack channel ID',
        'slack_channel_name' => 'Slack channel',
        'slack_contact_name' => 'Slack contact person',
        'slack_contact_member_id' => 'Slack member ID',
        'slack_contact_language' => 'Slack language',
        'mollie_customer_id' => 'Mollie customer',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Record an activity, by the signed-in user (or "System" for background work).
     *
     * @param  array<string, array{old: ?string, new: ?string}>|null  $changes
     */
    public static function record(?Affiliate $affiliate, string $event, string $description, ?array $changes = null): self
    {
        $user = auth()->user();

        return static::create([
            'affiliate_id' => $affiliate?->exists ? $affiliate->getKey() : null,
            'affiliate_name' => $affiliate?->name,
            'user_id' => $user?->getKey(),
            'user_name' => $user?->name,
            'event' => $event,
            'description' => $description,
            'changes' => $changes ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * The changed attributes of a model in its last save, as readable old and new values.
     *
     * @return array<string, array{old: ?string, new: ?string}>
     */
    public static function changesOf(Model $model): array
    {
        $changes = [];

        foreach (array_keys($model->getChanges()) as $attribute) {
            if (in_array($attribute, self::IgnoredAttributes, true)) {
                continue;
            }

            $secret = in_array($attribute, self::SecretAttributes, true);

            $changes[self::attributeLabel($attribute)] = [
                'old' => $secret ? null : self::readable($model->getOriginal($attribute)),
                'new' => $secret ? '(changed)' : self::readable($model->getAttribute($attribute)),
            ];
        }

        return $changes;
    }

    public static function attributeLabel(string $attribute): string
    {
        return self::AttributeLabels[$attribute] ?? Str::of($attribute)->headline()->lower()->ucfirst()->toString();
    }

    public static function readable(mixed $value): ?string
    {
        return match (true) {
            $value instanceof HasLabel => (string) $value->getLabel(),
            $value instanceof BackedEnum => (string) $value->value,
            is_bool($value) => $value ? 'Yes' : 'No',
            blank($value) => null,
            default => Str::limit((string) $value, 200),
        };
    }

    /**
     * @return BelongsTo<Affiliate, $this>
     */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who did it: the user, or "System" for background work such as syncs and webhooks.
     */
    public function causer(): string
    {
        return $this->user_name ?? 'System';
    }
}
