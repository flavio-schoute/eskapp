{{-- The Finance SOP: how the books are kept up to date in this app. --}}
@php
    $a = fn (string $url, string $label): string => '<a href="'.$url.'" style="color: var(--primary-600); text-decoration: underline; text-underline-offset: 2px;">'.e($label).'</a>';
    $external = fn (string $url, string $label): string => '<a href="'.$url.'" target="_blank" rel="noopener" style="color: var(--primary-600); text-decoration: underline; text-underline-offset: 2px;">'.e($label).' ↗</a>';
    $badge = fn (string $text): string => '<span style="margin-inline-start: auto; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; background: rgb(217 119 6 / 0.12); color: #B45309;">'.e($text).'</span>';
    $note = 'margin-top: 0.75rem; padding: 0.625rem 0.875rem; border-radius: 0.5rem; background: rgb(37 99 235 / 0.06); box-shadow: inset 0 0 0 1px rgb(37 99 235 / 0.2);';
    $warning = 'margin-top: 0.75rem; padding: 0.625rem 0.875rem; border-radius: 0.5rem; background: rgb(217 119 6 / 0.06); box-shadow: inset 0 0 0 1px rgb(217 119 6 / 0.25);';
    $list = 'display: flex; flex-direction: column; gap: 0.375rem; padding-inline-start: 1.25rem; list-style: decimal;';
    $bullets = 'display: flex; flex-direction: column; gap: 0.375rem; padding-inline-start: 1.25rem; list-style: disc;';
    $terms = 'display: grid; grid-template-columns: minmax(10rem, 14rem) 1fr; gap: 0.625rem 1rem;';
    $step = fn (int $n, string $title, ?string $frequency = null): string => '<div style="display: flex; align-items: center; gap: 0.625rem; margin-bottom: 0.75rem;"><span style="display: inline-flex; width: 1.5rem; height: 1.5rem; align-items: center; justify-content: center; border-radius: 0.375rem; background: #C2410C; color: white; font-size: 0.8rem; font-weight: 600;">'.$n.'</span><span style="font-size: 1.05rem; font-weight: 600;">'.e($title).'</span>'.($frequency ? $badge($frequency) : '').'</div>';
@endphp

<x-filament-panels::page>
    <div style="display: flex; flex-direction: column; gap: 1rem; max-width: 52rem; font-size: 0.925rem; line-height: 1.6;">
        <x-filament::section heading="Hoe de app rekent">
            <ul style="{{ $bullets }}">
                <li>De W&amp;V is op <b>kasbasis en exclusief btw</b>: wat er echt betaald is, in de maand van betaling.</li>
                <li><b>Omzet</b> komt uit de Plug&amp;Pay-orders: alleen <b>betaalde</b> orderregels, op de betaaldatum, per omzetstroom. Ze worden automatisch opgehaald (elk uur de laatste 4 maanden, elke nacht alles). Open, mislukte en gecrediteerde facturen tellen niet mee.</li>
                <li><b>Kosten</b> komen uit de bankexports. Een bankregel telt pas mee als hij een categorie heeft (bevestigd, of voorgesteld door een regel).</li>
                <li>Geld dat via de bank binnenkomt van Mollie, Stripe, PayPro e.d. is <b>geen extra omzet</b>: dat is al omzet via de orders. Die uitbetalingen staan onder <b>Interne overboekingen</b>.</li>
                <li>Kosten die nergens als losse bankregel staan, zoals Mollie-facturen die op de uitbetalingen worden ingehouden, voeg je met de hand toe (zie Correcties).</li>
            </ul>
            <p style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid rgb(127 127 127 / 0.15);">
                Wekelijkse routine: {!! $a($import, '1. Bank importeren') !!} → {!! $a($products, '2. Producten indelen') !!} → {!! $a($inbox, '3. Inbox leegwerken') !!} → {!! $a($signals, '4. Signalen nalopen') !!} → {!! $a($dashboard, '5. Dashboard controleren') !!}
            </p>
        </x-filament::section>

        <x-filament::section>
            {!! $step(1, 'Bankexport importeren', 'Wekelijks') !!}
            <ol style="{{ $list }}">
                <li>Download in de bankomgeving een <b>CSV-export</b> per rekening. Een ruime periode is prima: dubbele regels worden automatisch overgeslagen.</li>
                <li>Ga naar {!! $a($import, 'Importeren') !!} → <b>Bankexport</b> en kies de <b>rekening</b>. Nieuwe rekening? Klik op <b>+ Rekening</b> naast de keuzelijst.</li>
                <li>Sleep het bestand in het vak. ING (NL/EN), Rabobank en American Express worden herkend; bij een onbekend formaat geef je één keer aan welke kolom wat is.</li>
                <li>Controleer het voorbeeld (periode, nieuwe regels, totalen in/uit) en klik op <b>Importeer … transacties</b>. Regels die door een regel herkend worden krijgen meteen een categorie; klantbetalingen worden aan hun Plug&amp;Pay-order gekoppeld.</li>
            </ol>
            <div style="{{ $note }}"><b>Plug&amp;Pay-orders</b> komen vanzelf binnen. Toch handmatig nodig? Upload onder <b>Importeren → Plug&amp;Pay-orders</b> een export (XLSX of CSV). Regels worden op order en SKU gekoppeld: wat er al is wordt overgeslagen, alleen een gewijzigde betaalstatus wordt bijgewerkt.</div>
            <div style="{{ $note }}">Verkeerd bestand? Onderaan de importpagina staat de geschiedenis met <b>Ongedaan maken</b>.</div>
        </x-filament::section>

        <x-filament::section>
            {!! $step(2, 'Nieuwe producten indelen', 'Wekelijks') !!}
            <ol style="{{ $list }}">
                <li>Ga naar {!! $a($products, 'Producten') !!}. Nieuwe producten krijgen op basis van hun SKU vaak al vanzelf een omzetstroom; de rest staat bij <b>Nog niet ingedeeld</b>.</li>
                <li>Koppel elk product aan een <b>omzetstroom</b>. De keuze geldt voor alle producten met dezelfde SKU.</li>
                <li>Staat er een melding over <b>dubbele SKU's</b>, pas de SKU dan aan in Plug&amp;Pay. De nachtelijke sync neemt de nieuwe SKU over, ook op de orderregels.</li>
            </ol>
        </x-filament::section>

        <x-filament::section>
            {!! $step(3, 'Inbox leegwerken', 'Wekelijks') !!}
            <p>De {!! $a($inbox, 'Inbox') !!} toont de bankregels die nog een beslissing nodig hebben:</p>
            <ul style="{{ $bullets }} margin-top: 0.5rem;">
                <li><b>Open</b>: nog geen categorie. Kies er een (typ om te zoeken).</li>
                <li><b>Suggestie</b>: op basis van eerder geboekte regels van dezelfde tegenpartij. <b>Akkoord</b> neemt hem over.</li>
                <li><b>Voorgesteld door een regel</b> of door een <b>Plug&amp;Pay-order</b>: telt al mee. <b>Akkoord</b> bevestigt.</li>
            </ul>
            <div style="{{ $note }}"><b>Btw</b> pas je direct in de kolom Btw aan, ook vóór je een categorie kiest. <b>Splitsen</b> over meerdere categorieën kan via het potloodje (<b>Uitsplitsen</b>) of de schaar.</div>
            <div style="{{ $note }}"><b>Regel maken</b> (✦ of <kbd>r</kbd>): voor terugkerende posten. De regel deelt voortaan alle regels van die tegenpartij in. Meerdere voorwaarden moeten allemaal kloppen (EN).</div>
            <div style="{{ $note }}"><b>Klantbetaling per bank</b>: de kolom Plug&amp;Pay laat zien bij welke order een bijschrijving hoort. ✅ betaald → boek op <b>Interne overboekingen › Klantbetaling per bank (Plug &amp; Pay)</b>. ⚠️ staat open → zet de order in Plug&amp;Pay op betaald, anders telt de omzet niet. Boek zo'n regel nooit op een omzetcategorie: dan telt hij dubbel (de app waarschuwt).</div>
            <div style="{{ $note }}"><b>Sneltoetsen:</b> <kbd>j</kbd>/<kbd>k</kbd> volgende/vorige · <kbd>Enter</kbd> categorie · <kbd>a</kbd> akkoord · <kbd>r</kbd> regel maken · <kbd>x</kbd> uitsluiten · <kbd>spatie</kbd> selecteren · <kbd>Esc</kbd> stoppen.</div>
            <div style="{{ $warning }}">Twijfel je? Categoriseer niet op de gok. Laat de regel open en vraag het na.</div>
        </x-filament::section>

        <x-filament::section>
            {!! $step(4, 'Signalen nalopen', 'Wekelijks') !!}
            <p>Op {!! $a($signals, 'Signalen') !!} staat wat aandacht nodig heeft. Nieuwe signalen gaan elke ochtend om 08:45 ook naar <b>#finance</b> in Slack.</p>
            <ul style="{{ $bullets }} margin-top: 0.5rem;">
                <li><b>Bankbetalingen en Plug&amp;Pay</b>: geld binnen maar de order staat nog open, of een klantbetaling zonder order.</li>
                <li><b>Afwijkingen</b>: mogelijk dubbel betaald, of een ongewoon hoog bedrag.</li>
                <li><b>Budgetten</b> van deze maand en <b>abonnementen</b> (nieuw of duurder geworden).</li>
            </ul>
            <div style="{{ $note }}">Klopt een signaal? Klik op <b>Klopt</b>, dan verdwijnt hij en wordt hij niet meer gestuurd. Welke meldingen naar Slack gaan en de tekst ervan stel je in onder {!! $a($slackSettings, 'Slack settings → Finance alerts') !!}.</div>
        </x-filament::section>

        <x-filament::section>
            {!! $step(5, 'Dashboard controleren', 'Wekelijks') !!}
            <ol style="{{ $list }}">
                <li>Open het {!! $a($dashboard, 'Dashboard') !!}. In de ondertitel staat t/m welke datum bank en orders zijn bijgewerkt.</li>
                <li>Staat er <b>Nog niet compleet</b>, werk dan eerst de inbox en de niet-ingedeelde producten weg.</li>
                <li>Loop de W&amp;V na. Klik op een groep voor de posten, op een post of omzetstroom voor wat erachter zit, en op een bedrag voor die maand.</li>
                <li>Kijk ook naar de <b>ROAS</b>, de <b>cashflow-prognose</b> en de <b>marge per omzetstroom</b>.</li>
            </ol>
        </x-filament::section>

        <x-filament::section heading="Terugbetalen en crediteren">
            <p style="margin-bottom: 0.75rem;">Elke terugbetaling aan een klant via de bank boek je op <b>Refunds › Refund kosten</b>. Zet altijd het <b>factuurnummer</b> in de omschrijving van de overboeking: daarmee rekent de app het goed.</p>
            <dl style="{{ $terms }}">
                <dt style="font-weight: 600;">Hele factuur terug</dt>
                <dd>Crediteer de factuur in Plug&amp;Pay en stort terug via de ING. De creditering haalt de omzet al weg; de app herkent de terugbetaling (kolom Plug&amp;Pay: <i>gecrediteerd · telt niet mee</i>) en telt hem niet nog een keer als kosten.</dd>
                <dt style="font-weight: 600;">Deel terug</dt>
                <dd>Plug&amp;Pay kan niet deels crediteren. Stort het deel terug via de ING en maak een <b>deelcreditnota</b> met {!! $external('https://winkelfactuur.nl/nl/tools/creditnota-maken/', 'winkelfactuur.nl') !!} die verwijst naar het originele factuurnummer. Gebruik een eigen doorlopende nummering (bijv. CN-2026-001), stuur hem naar de klant en bewaar de pdf voor de boekhouder. De terugbetaling telt als Refund kosten.</dd>
                <dt style="font-weight: 600;">Crediteren zonder terugbetaling</dt>
                <dd>Bijv. een factuur sluiten en het bedrag aan het eind van de termijn opnieuw rekenen: alleen crediteren in Plug&amp;Pay. Er gaat geen geld over de bank, dus er is niets te boeken.</dd>
                <dt style="font-weight: 600;">Samenvoegen of corrigeren</dt>
                <dd>Facturen crediteren en vervangen door één nieuwe factuur die je op betaald zet: de nieuwe telt als omzet, de gecrediteerde niet.</dd>
                <dt style="font-weight: 600;">Wij krijgen geld terug</dt>
                <dd>Van een leverancier (bijv. een deel van een abonnement)? Boek de bijschrijving op dezelfde kostencategorie als de oorspronkelijke betaling. Dan worden die kosten lager.</dd>
            </dl>
            <div style="{{ $note }}">
                <b>Terugbetalen altijd via de ING</b>, met het factuurnummer in de omschrijving:
                <ul style="{{ $bullets }} margin-top: 0.375rem;">
                    <li>Via de ING staat de terugbetaling in de bankexport, zodat de app hem herkent en goed rekent.</li>
                    <li>Via Mollie (terugbetalen op de oorspronkelijke betaling) wordt het bedrag van de uitbetaling ingehouden. Er komt geen losse bankregel, dus de app ziet het niet. Bij een volledig gecrediteerde factuur maakt dat niet uit (de omzet is al weg), maar bij een deelterugbetaling telt de omzet dan te hoog. Toch via Mollie gedaan? Voeg hem met de hand toe via <b>Factuur toevoegen</b> als Refund kosten.</li>
                </ul>
            </div>
            <div style="{{ $warning }}">Op winkelfactuur.nl vul je klantgegevens in op een externe site: zet er alleen in wat op de creditnota moet staan.</div>
        </x-filament::section>

        <x-filament::section heading="Correcties en bijzondere gevallen">
            <p style="margin-bottom: 0.75rem;">Dit doe je in {!! $a($transactions, 'Transacties') !!}: zoek de regel op met de filters of de zoekbalk. Klik op een tegenpartij of bedrag om hem te kopiëren.</p>
            <dl style="{{ $terms }}">
                <dt style="font-weight: 600;">Verkeerde categorie</dt><dd>Kies een andere. Handmatig bevestigde regels worden nooit door een regel overschreven.</dd>
                <dt style="font-weight: 600;">Afwijkend btw-tarief</dt><dd>Pas de kolom <b>Btw</b> aan, of open het potloodje.</dd>
                <dt style="font-weight: 600;">Eén betaling, meerdere posten</dt><dd>Potloodje → <b>Uitsplitsen</b>, of de schaar. Per deel een categorie, bedrag en btw; de delen moeten precies optellen.</dd>
                <dt style="font-weight: 600;">Hoort bij een andere maand</dt><dd>Potloodje → <b>Telt in de W&amp;V in</b>, bijv. een jaarabonnement dat op 2 januari is afgeschreven maar bij december hoort.</dd>
                <dt style="font-weight: 600;">Kosten zonder bankregel</dt><dd><b>Factuur toevoegen</b> (rechtsboven, of Importeren → Factuur), bijv. Mollie-kosten die op de uitbetaling zijn ingehouden. Vul het bedrag incl. btw in en kies het tarief of het exacte btw-bedrag. Handmatige facturen kun je weer verwijderen.</dd>
                <dt style="font-weight: 600;">Context vastleggen</dt><dd>Potloodje → notitie (bijv. "Flavio - flat fee"). Notities zijn doorzoekbaar.</dd>
                <dt style="font-weight: 600;">Opnieuw beoordelen</dt><dd><b>Terug naar de inbox</b> haalt de categorie weg.</dd>
                <dt style="font-weight: 600;">Regel werkt niet goed</dt><dd>Pas hem aan onder {!! $a($rules, 'Regels') !!}. De bovenste regel die past wint; sleep om de volgorde te veranderen.</dd>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Instellingen">
            <dl style="{{ $terms }}">
                <dt style="font-weight: 600;">Uitleg per categorie</dt><dd>Bij het bewerken van een categorie vul je een <b>uitleg</b> in: wanneer je hem gebruikt en of hij meetelt. Die zie je als je over het ⓘ-icoon gaat (categorieën, W&amp;V), als je over de categorie van een transactie gaat, en onder de categoriekeuze.</dd>
                <dt style="font-weight: 600;">Categorieën</dt><dd>Onder {!! $a($categories, 'Categorieën') !!} stel je per kostencategorie ook een <b>maandbudget</b> in, de <b>omzetstroom</b> waar de kosten bij horen (voor de marge) en of hij als <b>adspend</b> telt (voor de ROAS).</dd>
                <dt style="font-weight: 600;">Slack</dt><dd>{!! $a($slackSettings, 'Slack settings → Finance alerts') !!}: kanaal, welke signalen, de berichtteksten en een testbericht.</dd>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Wie doet wat">
            <dl style="{{ $terms }}">
                <dt style="font-weight: 600;">Flavio</dt><dd>Wekelijkse routine (stap 1–5).</dd>
                <dt style="font-weight: 600;">Gairo</dt><dd>Beslist over de indeling: nieuwe categorieën, omzetstromen, budgetten en twijfelgevallen.</dd>
                <dt style="font-weight: 600;">Rechten</dt><dd>Finance is er voor de rollen <b>Admin</b> en <b>Bewerker</b>. Collega's voeg je toe onder Settings → Team.</dd>
            </dl>
            <div style="{{ $warning }}">Categorieën aanpassen verandert de indeling van de hele W&amp;V. Doe dat alleen na akkoord van Gairo.</div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
