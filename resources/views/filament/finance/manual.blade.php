{{-- The Finance SOP, adapted from the original Finance app: the Plug&Pay orders now come in automatically. --}}
@php
    $a = fn (string $url, string $label): string => '<a href="'.$url.'" style="color: var(--primary-600); text-decoration: underline; text-underline-offset: 2px;">'.e($label).'</a>';
    $badge = fn (string $text): string => '<span style="margin-inline-start: auto; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; background: rgb(217 119 6 / 0.12); color: #B45309;">'.e($text).'</span>';
    $note = 'margin-top: 0.75rem; padding: 0.625rem 0.875rem; border-radius: 0.5rem; background: rgb(37 99 235 / 0.06); box-shadow: inset 0 0 0 1px rgb(37 99 235 / 0.2);';
    $list = 'display: flex; flex-direction: column; gap: 0.375rem; padding-inline-start: 1.25rem; list-style: decimal;';
    $step = fn (int $n, string $title, ?string $frequency = null): string => '<div style="display: flex; align-items: center; gap: 0.625rem; margin-bottom: 0.75rem;"><span style="display: inline-flex; width: 1.5rem; height: 1.5rem; align-items: center; justify-content: center; border-radius: 0.375rem; background: #C2410C; color: white; font-size: 0.8rem; font-weight: 600;">'.$n.'</span><span style="font-size: 1.05rem; font-weight: 600;">'.e($title).'</span>'.($frequency ? $badge($frequency) : '').'</div>';
@endphp

<x-filament-panels::page>
    <div style="display: flex; flex-direction: column; gap: 1rem; max-width: 52rem; font-size: 0.925rem; line-height: 1.6;">
        <x-filament::section heading="Hoe de app rekent">
            <ul style="display: flex; flex-direction: column; gap: 0.375rem; padding-inline-start: 1.25rem; list-style: disc;">
                <li>De W&amp;V is op <b>kasbasis en exclusief btw</b>: wat er echt betaald is, in de maand van betaling.</li>
                <li><b>Kosten</b> komen uit de bankexport. Een bankregel telt pas mee als hij een categorie heeft (bevestigd, of voorgesteld via een regel).</li>
                <li><b>Omzet</b> komt uit de Plug&amp;Pay-orders: alleen betaalde orderregels, op betaaldatum, per omzetstroom. Die worden <b>automatisch</b> opgehaald: elk uur de laatste 4 maanden, elke nacht alles.</li>
                <li>Geld dat binnenkomt via de bank (uitbetalingen van Mollie, Stripe, PayPro e.d.) is dus <b>géén</b> extra omzet, anders tel je hem dubbel.</li>
                <li>Maakt een klant <b>zelf over per bank</b>, dan koppelt de app de bijschrijving aan de Plug&amp;Pay-order (op factuurnummer, ordernummer of bedrag). Boek hem op <b>Interne overboekingen › Klantbetaling per bank (Plug &amp; Pay)</b>: de omzet telt al via de order. Staat de order nog open in Plug&amp;Pay, of is er geen order, dan verschijnt hij onder Signalen en in #finance.</li>
            </ul>
            <p style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid rgb(127 127 127 / 0.15);">
                Wekelijkse routine: {!! $a($import, '1. Bank importeren') !!} → {!! $a($products, '2. Producten indelen') !!} → {!! $a($inbox, '3. Inbox leegwerken') !!} → {!! $a($dashboard, '4. Dashboard controleren') !!}
            </p>
        </x-filament::section>

        <x-filament::section>
            {!! $step(1, 'Bankexport importeren', 'Wekelijks') !!}
            <ol style="{{ $list }}">
                <li>Download in de bankomgeving een <b>CSV-export</b> van de afgelopen periode, per rekening. Een ruime periode is prima: dubbele regels worden automatisch overgeslagen.</li>
                <li>Ga naar {!! $a($import, 'Importeren') !!} → tab <b>Bankexport</b> en kies de juiste <b>rekening</b>. Nieuwe rekening? Voeg hem toe onder Rekeningen, met naam en IBAN.</li>
                <li>Sleep het bestand in het vak. De app herkent ING (NL/EN), Rabobank en American Express zelf; bij een onbekend formaat geef je één keer aan welke kolom wat is. Dat wordt per rekening onthouden.</li>
                <li>Controleer het voorbeeld: periode, aantal nieuwe regels en de totalen in/uit. Staat er een gele melding dat het IBAN niet bij de rekening past, dan heb je de verkeerde rekening gekozen.</li>
                <li>Klik op <b>Importeer … transacties</b>. Regels die door een regel herkend worden, krijgen meteen een categorie; de rest staat in de inbox.</li>
            </ol>
            <div style="{{ $note }}">Verkeerd bestand of verkeerde rekening? Onderaan de importpagina staat de importgeschiedenis met <b>Ongedaan maken</b>. Dat verwijdert alle regels uit die import, ook de regels die je al had gecategoriseerd.</div>
        </x-filament::section>

        <x-filament::section>
            {!! $step(2, 'Nieuwe producten indelen', 'Wekelijks') !!}
            <ol style="{{ $list }}">
                <li>Ga naar {!! $a($products, 'Producten') !!} en filter op <b>Nog niet ingedeeld</b>. Nieuwe producten uit Plug&amp;Pay komen hier vanzelf bij zodra ze besteld zijn.</li>
                <li>Koppel elk product aan een <b>omzetstroom</b> (bijv. Coaching sales, Upsell sales, Event sales). Past geen enkele stroom, maak er dan een nieuwe aan, maar overleg nieuwe stromen eerst met Gairo.</li>
            </ol>
            <div style="{{ $note }}">Zolang een product niet is ingedeeld, telt de omzet wel mee maar onder "Nog niet ingedeeld". Het dashboard meldt dit bovenaan.</div>
        </x-filament::section>

        <x-filament::section>
            {!! $step(3, 'Inbox leegwerken', 'Wekelijks') !!}
            <p>De {!! $a($inbox, 'Inbox') !!} toont alle bankregels die nog aandacht nodig hebben:</p>
            <ul style="display: flex; flex-direction: column; gap: 0.375rem; padding-inline-start: 1.25rem; list-style: disc; margin-top: 0.5rem;">
                <li><b>Open</b>: nog geen categorie. Kies er een met de categoriekiezer (typ om te zoeken).</li>
                <li><b>Suggestie</b>: de app stelt een categorie voor op basis van eerdere regels van dezelfde tegenpartij. Klik <b>Akkoord</b> om hem over te nemen, of kies zelf een andere categorie.</li>
                <li><b>Voorgesteld door een regel</b>: telt al mee in de W&amp;V. Klik <b>Akkoord</b> om te bevestigen, of kies een andere categorie als het niet klopt.</li>
            </ul>
            <div style="{{ $note }}"><b>Regel maken:</b> voor terugkerende posten (abonnementen, vaste leveranciers) klik je op ✦ <b>Regel maken</b>. De regel krijgt de categorie en deelt voortaan alle regels van die tegenpartij automatisch in. Eenmalige posten: kies alleen een categorie.</div>
            <div style="{{ $note }}"><b>Veel regels tegelijk:</b> vink ze aan en kies in de balk <b>Categorie toewijzen</b>, <b>Voorstel akkoord</b> of <b>Uitsluiten</b>.</div>
            <div style="{{ $note }}"><b>Uitsluiten</b> haalt een regel volledig uit de W&amp;V. Gebruik dit alleen voor regels die nergens thuishoren. Voor overboekingen tussen eigen rekeningen, PSP-uitbetalingen, belastingen en leningen zijn aparte categorieën: die tellen niet als kosten, maar blijven wel terug te vinden.</div>
            <div style="{{ $note }}"><b>Sneltoetsen:</b> <kbd>j</kbd>/<kbd>k</kbd> volgende/vorige · <kbd>Enter</kbd> categorie kiezen · <kbd>a</kbd> voorstel of suggestie akkoord · <kbd>r</kbd> regel maken · <kbd>x</kbd> uitsluiten · <kbd>spatie</kbd> selecteren · <kbd>Esc</kbd> stoppen.</div>
            <div style="{{ $note }} background: rgb(217 119 6 / 0.06); box-shadow: inset 0 0 0 1px rgb(217 119 6 / 0.25);">Twijfel je over een post? Categoriseer niet op de gok. Laat hem open staan en vraag het na: een foute categorie valt later veel minder op dan een open regel.</div>
        </x-filament::section>

        <x-filament::section>
            {!! $step(4, 'Dashboard controleren', 'Wekelijks') !!}
            <ol style="{{ $list }}">
                <li>Open het {!! $a($dashboard, 'Dashboard') !!}. In de ondertitel staat t/m welke datum bank en orders zijn bijgewerkt; dat moet ongeveer vandaag zijn.</li>
                <li>Staat er bovenaan <b>Nog niet compleet</b>, dan zijn er nog open inboxregels of niet-ingedeelde producten. Werk die eerst weg.</li>
                <li>Kies jaar en periode en loop de W&amp;V na. Klik op een kostengroep voor de posten, en op een bedrag voor de onderliggende transacties.</li>
            </ol>
        </x-filament::section>

        <x-filament::section heading="Correcties en bijzondere gevallen">
            <p style="margin-bottom: 0.75rem;">Alles hieronder doe je in {!! $a($transactions, 'Transacties') !!}: zoek de regel op (filter op rekening, status, categorie, periode of zoekterm). Klik op een tegenpartij of bedrag om hem te kopiëren.</p>
            <dl style="display: grid; grid-template-columns: minmax(10rem, 14rem) 1fr; gap: 0.5rem 1rem;">
                <dt style="font-weight: 600;">Verkeerde categorie</dt><dd>Kies een andere categorie. Handmatig bevestigde regels worden nooit meer door een regel overschreven.</dd>
                <dt style="font-weight: 600;">Afwijkend btw-tarief</dt><dd>Open <b>Details</b> en kies het juiste btw-tarief (standaard volgt het de categorie). De W&amp;V rekent met het bedrag exclusief btw.</dd>
                <dt style="font-weight: 600;">Eén betaling, meerdere posten</dt><dd>Gebruik <b>Splitsen</b> en verdeel het bedrag over meerdere categorieën, bijv. een creditcardafrekening.</dd>
                <dt style="font-weight: 600;">Kosten horen bij een andere maand</dt><dd>Zet in <b>Details</b> de maand waarin hij telt, bijv. een jaarabonnement dat op 2 januari is afgeschreven maar bij december hoort.</dd>
                <dt style="font-weight: 600;">Context vastleggen</dt><dd>Vul in <b>Details</b> een notitie in (bijv. "Flavio - flat fee"). Notities zijn doorzoekbaar.</dd>
                <dt style="font-weight: 600;">Opnieuw beoordelen</dt><dd><b>Terug naar de inbox</b> haalt de categorie weg en zet de regel weer open.</dd>
                <dt style="font-weight: 600;">Regel werkt niet goed</dt><dd>Pas hem aan onder {!! $a($rules, 'Regels') !!}. De bovenste regel die past wint; sleep regels om de volgorde te veranderen. Bij het bewerken zie je hoeveel bestaande transacties erop passen.</dd>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Wie doet wat">
            <dl style="display: grid; grid-template-columns: minmax(10rem, 14rem) 1fr; gap: 0.5rem 1rem;">
                <dt style="font-weight: 600;">Flavio</dt><dd>Wekelijkse routine (stap 1–4): importeren, producten indelen, inbox leegwerken.</dd>
                <dt style="font-weight: 600;">Gairo</dt><dd>Beslist over de indeling: nieuwe categorieën, omzetstromen en twijfelgevallen.</dd>
                <dt style="font-weight: 600;">Rechten</dt><dd>Finance is er voor de rollen <b>Admin</b> en <b>Bewerker</b>. Collega's voeg je toe onder Settings → Team.</dd>
            </dl>
            <div style="{{ $note }} background: rgb(217 119 6 / 0.06); box-shadow: inset 0 0 0 1px rgb(217 119 6 / 0.25);">Categorieën aanpassen verandert de indeling van de hele W&amp;V. Doe dat alleen na akkoord van Gairo.</div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
