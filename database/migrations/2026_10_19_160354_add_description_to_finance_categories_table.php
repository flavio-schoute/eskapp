<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An explanation per category, shown when hovering over it: when to use it and whether it counts in the P&L.
 */
return new class extends Migration
{
    /**
     * The categories that are easy to mix up, by code.
     *
     * @var array<string, string>
     */
    private const Descriptions = [
        '700' => 'Elke terugbetaling aan een klant via de bank, ook een deel met een deelcreditnota. Zet het factuurnummer in de omschrijving van de overboeking: hoort de terugbetaling bij een factuur die in Plug & Pay helemaal is gecrediteerd, dan telt hij automatisch niet mee (de creditering haalde de omzet al weg).',
        '454545' => 'Affiliate-inkomsten die per bank binnenkomen en niet via Plug & Pay lopen. Telt als omzet.',
        'TR-PSP' => 'Uitbetalingen van Mollie, Stripe, PayPro, GGN en Collect4u. Dat is klantgeld dat al als omzet telt via de Plug & Pay-orders, dus telt niet mee.',
        'TR-PNP' => 'Een klant maakte zelf over per bank. De omzet telt al via de Plug & Pay-order, dus telt niet mee. Staat de order nog open? Zet hem in Plug & Pay op betaald.',
        'TR-CC' => 'Afschrijving van de creditcard (bijv. Amex) vanaf de bank. De kosten staan al per regel in de creditcardexport, dus telt niet mee.',
        'TR-OWN' => 'Geld tussen eigen rekeningen, bijv. naar de spaarrekening. Telt niet mee.',
        'TAX-BTW' => 'Btw-afdracht aan of teruggave van de Belastingdienst. Telt niet mee in de W&V.',
        'TAX-VPB' => 'Vennootschapsbelasting aan de Belastingdienst. Telt niet mee in de W&V.',
        'FIN-LOAN' => 'Opname en aflossing van leningen (bijv. Bridgefund). Geen omzet of kosten, dus telt niet mee.',
        'FIN-RC' => 'Geld van of naar aandeelhouders via de rekening-courant. Telt niet mee.',
        '100' => 'Google Ads. Telt mee in de ROAS.',
        '101' => 'Facebook- en Instagram-advertenties. Facebook Ireland rekent btw verlegd, dus 0% btw. Telt mee in de ROAS.',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });

        foreach (self::Descriptions as $code => $description) {
            DB::table('finance_categories')->where('code', (string) $code)->whereNull('description')->update(['description' => $description]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
