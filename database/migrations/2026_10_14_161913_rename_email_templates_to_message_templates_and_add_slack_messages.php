<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email templates become message templates for both email and Slack: adds the Slack invoice messages,
 * the Slack contact's language, and remembers when an invoice was posted in Slack.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $slackMessages = [
        'nl' => 'Hey {mention}, hier is de factuur voor {month} ({invoice_number}). We hebben hem naar {email} gestuurd, het bedrag is {amount}. Check je inbox!',
        'en' => 'Hey {mention}, here is the invoice for {month} ({invoice_number}). We sent it to {email}, the amount is {amount}. Please check your inbox!',
        'de' => 'Hallo {mention}, hier ist die Rechnung für {month} ({invoice_number}). Wir haben sie an {email} geschickt, der Betrag ist {amount}. Bitte prüfe deinen Posteingang!',
        'fr' => 'Bonjour {mention}, voici la facture pour {month} ({invoice_number}). Nous l’avons envoyée à {email}, le montant est de {amount}. Merci de vérifier votre boîte de réception !',
    ];

    /**
     * @var array<string, string>
     */
    private array $slackMessageNames = [
        'nl' => 'Standaard (Nederlands)',
        'en' => 'Default (English)',
        'de' => 'Standard (Deutsch)',
        'fr' => 'Standard (Français)',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('email_templates', 'message_templates');

        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('subject')->nullable()->change();
        });

        foreach ($this->slackMessages as $language => $body) {
            DB::table('message_templates')->insert([
                'type' => 'slack_invoice',
                'name' => $this->slackMessageNames[$language],
                'language' => $language,
                'subject' => null,
                'body' => $body,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('affiliates', function (Blueprint $table) {
            $table->string('slack_contact_language', 5)->nullable()->after('slack_contact_member_id');
        });

        Schema::table('affiliate_invoices', function (Blueprint $table) {
            $table->timestamp('slack_posted_at')->nullable()->after('pdf_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliate_invoices', function (Blueprint $table) {
            $table->dropColumn('slack_posted_at');
        });

        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn('slack_contact_language');
        });

        DB::table('message_templates')->where('type', 'slack_invoice')->delete();

        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('subject')->nullable(false)->change();
        });

        Schema::rename('message_templates', 'email_templates');
    }
};
