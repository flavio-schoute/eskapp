<?php

namespace App\Models;

use App\Enums\EmailTemplateType;
use App\Enums\InvoiceLanguage;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

#[Fillable(['type', 'name', 'language', 'subject', 'body', 'is_default'])]
class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EmailTemplateType::class,
            'language' => InvoiceLanguage::class,
            'is_default' => 'boolean',
        ];
    }

    /**
     * Keep only one default template per type and language.
     */
    protected static function booted(): void
    {
        static::saved(function (EmailTemplate $template): void {
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
    public static function defaultFor(EmailTemplateType $type, InvoiceLanguage $language): ?self
    {
        return static::query()->where('type', $type)->where('is_default', true)->where('language', $language)->first()
            ?? static::query()->where('type', $type)->where('is_default', true)->orderByRaw('language = ? desc', [InvoiceLanguage::English->value])->first();
    }

    /**
     * Fill in the placeholders of a subject or body for an affiliate and invoice period.
     */
    public static function fillPlaceholders(string $text, Affiliate $affiliate, Carbon $period, InvoiceLanguage $language): string
    {
        return strtr($text, [
            '{contact_person}' => $affiliate->invoice_contact_person ?: $affiliate->invoice_company_name,
            '{company_name}' => (string) $affiliate->invoice_company_name,
            '{partner_name}' => (string) $affiliate->name,
            '{month}' => $period->copy()->locale($language->value)->translatedFormat('F Y'),
        ]);
    }

    /**
     * This template's subject and body with the placeholders filled in.
     *
     * @return array{subject: string, body: string}
     */
    public function renderFor(Affiliate $affiliate, Carbon $period): array
    {
        return [
            'subject' => static::fillPlaceholders($this->subject, $affiliate, $period, $this->language),
            'body' => static::fillPlaceholders($this->body, $affiliate, $period, $this->language),
        ];
    }
}
