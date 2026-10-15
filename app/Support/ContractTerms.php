<?php

namespace App\Support;

use App\Models\Affiliate;
use Illuminate\Support\Carbon;

/**
 * The terms filled in on the affiliate contract ("Samenwerkingsovereenkomst Affiliate Partner") and how they are
 * written into the template's placeholders.
 */
class ContractTerms
{
    public const PercentageOfRevenue = 'percentage_of_revenue';

    public const FixedAmountOfRevenue = 'fixed_amount_of_revenue';

    public const FixedAmountPerCustomer = 'fixed_amount_per_customer';

    public const CustomCompensation = 'custom';

    /**
     * @return array<string, string>
     */
    public static function legalForms(): array
    {
        return [
            'B.V.' => 'B.V.',
            'N.V.' => 'N.V.',
            'Eenmanszaak' => 'Eenmanszaak',
            'V.O.F.' => 'V.O.F.',
            'C.V.' => 'C.V.',
            'Maatschap' => 'Maatschap',
            'Stichting' => 'Stichting',
            'Vereniging' => 'Vereniging',
            'Coöperatie' => 'Coöperatie',
            'Buitenlandse onderneming' => 'Buitenlandse onderneming',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function compensationOptions(): array
    {
        return [
            self::PercentageOfRevenue => 'Percentage over de omzet',
            self::FixedAmountOfRevenue => 'Vast bedrag over de omzet (per maand)',
            self::FixedAmountPerCustomer => 'Vast bedrag per Aangebrachte Klant',
            self::CustomCompensation => 'Andere grondslag (eigen tekst)',
        ];
    }

    /**
     * @return list<string>
     */
    public static function noticePeriodOptions(): array
    {
        return ['1 week', '2 weken', '1 maand', '2 maanden', '3 maanden'];
    }

    /**
     * Article 4.1 for a compensation basis and amount.
     */
    public static function compensationText(?string $basis, float|int|string|null $amount): string
    {
        $amount = filled($amount) ? (float) str_replace(',', '.', (string) $amount) : null;
        $percentage = $amount === null ? '[PERCENTAGE]' : rtrim(rtrim(number_format($amount, 2, ',', '.'), '0'), ',').'%';
        $money = $amount === null ? '€ [BEDRAG]' : '€ '.(fmod($amount, 1.0) === 0.0 ? number_format($amount, 0, ',', '.').',-' : number_format($amount, 2, ',', '.'));

        return match ($basis) {
            self::PercentageOfRevenue => "e-Skool ontvangt van Partner een vergoeding van {$percentage} over de omzet die Partner realiseert met Aangebrachte Klanten, zoals nader overeengekomen tussen Partijen.",
            self::FixedAmountOfRevenue => "e-Skool ontvangt van Partner een vergoeding van een vast bedrag van {$money} per maand over de omzet die Partner realiseert met Aangebrachte Klanten, zoals nader overeengekomen tussen Partijen.",
            self::FixedAmountPerCustomer => "e-Skool ontvangt van Partner een vergoeding van een vast bedrag van {$money} per Aangebrachte Klant, zoals nader overeengekomen tussen Partijen.",
            default => 'e-Skool ontvangt van Partner een vergoeding van ',
        };
    }

    /**
     * The legal form and registration number as written after the partner's name, e.g. "B.V., KvK-nummer 12345678".
     */
    public static function legalFormAndRegistration(?string $legalForm, ?string $registrationNumber, ?string $countryCode = null): string
    {
        $registrationLabel = in_array($countryCode, [null, '', 'NL'], true) ? 'KvK-nummer' : 'registratienummer';

        return collect([
            $legalForm,
            filled($registrationNumber) ? "{$registrationLabel} ".trim($registrationNumber) : null,
        ])->filter()->implode(', ');
    }

    /**
     * The form defaults for a new contract: the terms of the affiliate's previous contract, or its details.
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(Affiliate $affiliate): array
    {
        $previous = $affiliate->latestContract?->fields ?? [];

        return [
            'partner_name' => $previous['partner_name'] ?? ($affiliate->invoice_company_name ?: $affiliate->name),
            'legal_form' => $previous['legal_form'] ?? null,
            'registration_number' => $affiliate->invoice_kvk_number ?? $previous['registration_number'] ?? null,
            'city' => $affiliate->invoice_city ?? $previous['city'] ?? null,
            'compensation_basis' => $previous['compensation_basis'] ?? self::PercentageOfRevenue,
            'compensation_amount' => $previous['compensation_amount'] ?? null,
            'compensation' => $previous['compensation'] ?? self::compensationText(self::PercentageOfRevenue, null),
            'other_agreements' => $previous['other_agreements'] ?? 'Geen.',
            'start_date' => now()->toDateString(),
            'notice_period' => $previous['notice_period'] ?? '1 week',
            'signer_name' => $affiliate->latestContract->signer_name ?? $affiliate->invoice_contact_person,
            'signer_email' => $affiliate->latestContract->signer_email ?? $affiliate->invoice_email,
        ];
    }

    /**
     * The template placeholder values for the filled-in contract form.
     *
     * @param  array<string, mixed>  $terms
     * @return array<string, string>
     */
    public static function placeholders(array $terms, ?string $countryCode = null): array
    {
        return [
            'partner_naam' => trim((string) $terms['partner_name']),
            'partner_rechtsvorm_kvk' => self::legalFormAndRegistration($terms['legal_form'] ?? null, $terms['registration_number'] ?? null, $countryCode),
            'partner_plaats' => trim((string) $terms['city']),
            'vergoeding' => trim((string) $terms['compensation']),
            'overige_afspraken' => trim((string) ($terms['other_agreements'] ?? '')) ?: 'Geen.',
            'ingangsdatum' => Carbon::parse($terms['start_date'] ?? now())->locale('nl')->translatedFormat('j F Y'),
            'opzegtermijn' => trim((string) ($terms['notice_period'] ?? '')) ?: '1 week',
        ];
    }
}
