<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the default Slack invoice messages with the final wording (Slack bold uses single asterisks).
 * Templates that were edited in the panel are left alone.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $oldMessages = [
        'nl' => "Hé {mention},\n\nDe factuur voor {month}, met factuurnummer {invoice_number}, is verstuurd naar {email}. Bekijk alsjeblieft even je mailbox.\n\nHeb je vragen? Laat het gerust weten. Laat ook even weten wanneer de factuur is betaald.\n\nDankjewel!",
        'en' => "Hey {mention},\n\nThe invoice for {month}, invoice number {invoice_number}, has been sent to {email}. Please take a quick look at your inbox.\n\nAny questions? Just let us know. Please also let us know once the invoice has been paid.\n\nThank you!",
        'de' => "Hallo {mention},\n\ndie Rechnung für {month} mit der Rechnungsnummer {invoice_number} wurde an {email} gesendet. Bitte schau kurz in dein Postfach.\n\nHast du Fragen? Melde dich gerne. Gib uns bitte auch Bescheid, sobald die Rechnung bezahlt ist.\n\nVielen Dank!",
        'fr' => "Bonjour {mention},\n\nLa facture pour {month}, numéro {invoice_number}, a été envoyée à {email}. Merci de jeter un œil à votre boîte de réception.\n\nDes questions ? N’hésitez pas à nous le dire. Merci aussi de nous prévenir une fois la facture payée.\n\nMerci !",
    ];

    /**
     * @var array<string, string>
     */
    private array $newMessages = [
        'nl' => "Hé {mention},\n\nDe factuur voor *{month}* (factuurnummer: {invoice_number}) is zojuist verstuurd naar *{email}*.\n\nWil je controleren of je de factuur goed hebt ontvangen?\n\nMocht je vragen hebben, laat het gerust weten. Graag ontvangen we ook een korte bevestiging zodra de betaling is voldaan.\n\nAlvast bedankt!",
        'en' => "Hi {mention},\n\nThe invoice for *{month}* (invoice number: {invoice_number}) has just been sent to *{email}*.\n\nCould you check that you have received it?\n\nIf you have any questions, just let us know. We would also appreciate a short confirmation once the payment has been made.\n\nThanks in advance!",
        'de' => "Hallo {mention},\n\ndie Rechnung für *{month}* (Rechnungsnummer: {invoice_number}) wurde soeben an *{email}* gesendet.\n\nKannst du kurz prüfen, ob du die Rechnung erhalten hast?\n\nWenn du Fragen hast, melde dich gerne. Wir freuen uns auch über eine kurze Bestätigung, sobald die Zahlung erfolgt ist.\n\nVielen Dank im Voraus!",
        'fr' => "Bonjour {mention},\n\nLa facture pour *{month}* (numéro de facture : {invoice_number}) vient d’être envoyée à *{email}*.\n\nPourriez-vous vérifier que vous l’avez bien reçue ?\n\nSi vous avez des questions, n’hésitez pas à nous le dire. Nous apprécierions également une courte confirmation une fois le paiement effectué.\n\nMerci d’avance !",
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
