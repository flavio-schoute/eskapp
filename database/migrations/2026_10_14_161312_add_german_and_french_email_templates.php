<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds default Mollie invoice email templates in German and French, for partners with those preferred languages.
 * Languages that already have a default template are left alone.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{name: string, subject: string, body: string}>
     */
    private array $templates = [
        'de' => [
            'name' => 'Standard (Deutsch)',
            'subject' => 'Rechnung Affiliate-Provision {month}',
            'body' => "Hallo {contact_person},\n\nanbei erhalten Sie die Rechnung für die Affiliate-Provision für {month}.\n\nMit freundlichen Grüßen\ne-Skool",
        ],
        'fr' => [
            'name' => 'Standard (Français)',
            'subject' => 'Facture commission d’affiliation {month}',
            'body' => "Bonjour {contact_person},\n\nVeuillez trouver ci-joint la facture de la commission d’affiliation pour {month}.\n\nCordialement,\ne-Skool",
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->templates as $language => $template) {
            $hasDefault = DB::table('email_templates')
                ->where('type', 'mollie_invoice')
                ->where('language', $language)
                ->where('is_default', true)
                ->exists();

            if ($hasDefault) {
                continue;
            }

            DB::table('email_templates')->insert([
                'type' => 'mollie_invoice',
                'language' => $language,
                ...$template,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->templates as $language => $template) {
            DB::table('email_templates')
                ->where('type', 'mollie_invoice')
                ->where('language', $language)
                ->where('name', $template['name'])
                ->delete();
        }
    }
};
