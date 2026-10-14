<?php

namespace App\Models;

use App\Enums\InvoiceLanguage;
use App\Enums\MessageTemplateType;
use Database\Factories\MessageTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

#[Fillable(['type', 'name', 'language', 'subject', 'body', 'is_default'])]
class MessageTemplate extends Model
{
    /** @use HasFactory<MessageTemplateFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MessageTemplateType::class,
            'language' => InvoiceLanguage::class,
            'is_default' => 'boolean',
        ];
    }

    /**
     * Keep only one default template per type and language.
     */
    protected static function booted(): void
    {
        static::saved(function (MessageTemplate $template): void {
            if ($template->is_default) {
                static::query()
                    ->where('type', $template->type)
                    ->where('language', $template->language)
                    ->whereKeyNot($template->getKey())
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * The default template for a type in a language, falling back to any default of that type.
     */
    public static function defaultFor(MessageTemplateType $type, InvoiceLanguage $language): ?self
    {
        return static::query()->where('type', $type)->where('is_default', true)->where('language', $language)->first()
            ?? static::query()->where('type', $type)->where('is_default', true)->orderByRaw('language = ? desc', [InvoiceLanguage::English->value])->first();
    }

    /**
     * Fill in the placeholders of a subject or body for an affiliate and invoice period. Extra placeholders, such as
     * the invoice number or amount, can be passed in as `['{placeholder}' => 'value']`.
     *
     * @param  array<string, string>  $extra
     */
    public static function fillPlaceholders(string $text, Affiliate $affiliate, Carbon $period, InvoiceLanguage $language, array $extra = []): string
    {
        return strtr($text, [
            '{contact_person}' => $affiliate->invoice_contact_person ?: (string) $affiliate->invoice_company_name,
            '{company_name}' => (string) $affiliate->invoice_company_name,
            '{partner_name}' => (string) $affiliate->name,
            '{month}' => $period->copy()->locale($language->value)->translatedFormat('F Y'),
            ...$extra,
        ]);
    }

    /**
     * This template's subject and body with the placeholders filled in.
     *
     * @param  array<string, string>  $extra
     * @return array{subject: string, body: string}
     */
    public function renderFor(Affiliate $affiliate, Carbon $period, array $extra = []): array
    {
        return [
            'subject' => static::fillPlaceholders((string) $this->subject, $affiliate, $period, $this->language, $extra),
            'body' => static::fillPlaceholders($this->body, $affiliate, $period, $this->language, $extra),
        ];
    }
}
