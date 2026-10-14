<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the default Slack invoice messages with the new wording. Templates that were edited in the panel
 * (their text differs from the old default) are left alone.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $oldMessages = [
        'nl' => 'Hey {mention}, hier is de factuur voor {month} ({invoice_number}). We hebben hem naar {email} gestuurd, het bedrag is {amount}. Check je inbox!',
        'en' => 'Hey {mention}, here is the invoice for {month} ({invoice_number}). We sent it to {email}, the amount is {amount}. Please check your inbox!',
        'de' => 'Hallo {mention}, hier ist die Rechnung für {month} ({invoice_number}). Wir haben sie an {email} geschickt, der Betrag ist {amount}. Bitte prüfe deinen Posteingang!',
        'fr' => 'Bonjour {mention}, voici la facture pour {month} ({invoice_number}). Nous l’avons envoyée à {email}, le montant est de {amount}. Merci de vérifier votre boîte de réception !',
    ];

    /**
     * @var array<string, string>
     */
    private array $newMessages = [
        'nl' => "Hé {mention},\n\nDe factuur voor {month}, met factuurnummer {invoice_number}, is verstuurd naar {email}. Bekijk alsjeblieft even je mailbox.\n\nHeb je vragen? Laat het gerust weten. Laat ook even weten wanneer de factuur is betaald.\n\nDankjewel!",
        'en' => "Hey {mention},\n\nThe invoice for {month}, invoice number {invoice_number}, has been sent to {email}. Please take a quick look at your inbox.\n\nAny questions? Just let us know. Please also let us know once the invoice has been paid.\n\nThank you!",
        'de' => "Hallo {mention},\n\ndie Rechnung für {month} mit der Rechnungsnummer {invoice_number} wurde an {email} gesendet. Bitte schau kurz in dein Postfach.\n\nHast du Fragen? Melde dich gerne. Gib uns bitte auch Bescheid, sobald die Rechnung bezahlt ist.\n\nVielen Dank!",
        'fr' => "Bonjour {mention},\n\nLa facture pour {month}, numéro {invoice_number}, a été envoyée à {email}. Merci de jeter un œil à votre boîte de réception.\n\nDes questions ? N’hésitez pas à nous le dire. Merci aussi de nous prévenir une fois la facture payée.\n\nMerci !",
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->replace($this->oldMessages, $this->newMessages);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->replace($this->newMessages, $this->oldMessages);
    }

    /**
     * @param  array<string, string>  $from
     * @param  array<string, string>  $to
     */
    private function replace(array $from, array $to): void
    {
        foreach ($from as $language => $body) {
            DB::table('message_templates')
                ->where('type', 'slack_invoice')
                ->where('language', $language)
                ->where('body', $body)
                ->update(['body' => $to[$language], 'updated_at' => now()]);
        }
    }
};
