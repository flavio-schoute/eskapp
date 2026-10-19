<?php

namespace App\Support\Finance;

/**
 * The revenue stream per Plug&Pay SKU, as set in the Finance (Next.js) app on 19 Oct 2026. Applied to every product
 * with that SKU, also products that are deleted in Plug&Pay, and to new products whose SKU is already known.
 */
class ProductStreams
{
    public const Coaching = 'Coaching sales';

    public const Upsell = 'Upsell sales';

    public const EventCommunity = 'Event community memberships';

    public const Events = 'Event sales';

    public const Affiliate = 'Affiliate inkomsten';

    public const EScale = 'e-Scale';

    public const Other = 'Overig / Intern';

    /**
     * @var array<string, string>
     */
    public const PerSku = [
        // Products with revenue.
        '888999' => self::Coaching, '863488' => self::Coaching, '1063488' => self::Upsell, '863989' => self::Coaching,
        '432450' => self::Upsell, '259254' => self::EventCommunity, '778949' => self::Events, '360758' => self::Other,
        '795055' => self::Coaching, '103963488' => self::Upsell, '795056' => self::Coaching, '963989' => self::Coaching,
        '512225' => self::Other, '409806' => self::Affiliate, '432451' => self::Upsell, '778947' => self::Events,
        '432448' => self::Upsell, '944618' => self::Other, '887999' => self::Coaching, '261411' => self::Coaching,
        '530222' => self::Coaching, '697368' => self::Coaching, '863489' => self::Coaching, '290602' => self::Coaching,
        '842829' => self::Coaching,
        // Products without orders since the Finance app started.
        '101615' => self::Events, '101616' => self::Events, '101617' => self::Events, '114515' => self::Events,
        '129781' => self::Other, '135694' => self::Coaching, '199550' => self::Coaching, '199554' => self::Coaching,
        '259248' => self::EventCommunity, '259249' => self::EventCommunity, '259251' => self::Events, '259252' => self::Events,
        '259253' => self::EventCommunity, '259255' => self::Events, '259256' => self::EventCommunity,
        '260046' => self::Coaching, '260047' => self::Coaching, '260048' => self::Coaching, '260049' => self::Coaching,
        '260050' => self::Coaching, '260051' => self::Coaching, '260052' => self::Coaching, '261650' => self::Coaching,
        '268562' => self::Coaching, '275347' => self::Other, '300691' => self::Coaching,
        '343536' => self::Coaching, '343537' => self::Coaching, '343538' => self::Coaching, '343539' => self::Coaching,
        '343540' => self::Coaching, '343541' => self::Coaching, '343542' => self::Coaching,
        '357910' => self::Other, '357911' => self::Other,
        '360454' => self::Coaching, '360455' => self::Coaching, '360456' => self::Coaching, '360457' => self::Coaching,
        '360458' => self::Coaching, '360459' => self::Coaching, '360460' => self::Coaching,
        '360557' => self::Coaching, '360558' => self::Coaching, '360559' => self::Coaching, '360560' => self::Coaching,
        '366361' => self::Coaching, '386332' => self::EScale, '386333' => self::EScale, '386334' => self::EScale,
        '409706' => self::Other, '409709' => self::Upsell, '432445' => self::Coaching, '432446' => self::Upsell,
        '432447' => self::Upsell, '432452' => self::Upsell, '432453' => self::Upsell, '432454' => self::Upsell,
        '432455' => self::Upsell, '432456' => self::Upsell, '432457' => self::Upsell, '432461' => self::Upsell,
        '432462' => self::Upsell, '432463' => self::Upsell, '432464' => self::Upsell, '432471' => self::Upsell,
        '432472' => self::Upsell, '432473' => self::Upsell, '432474' => self::Upsell, '433474' => self::Upsell,
        '433475' => self::Upsell, '434985' => self::Coaching, '442457' => self::Upsell, '445948' => self::Upsell,
        '4463488' => self::Upsell, '454545' => self::Affiliate, '456444' => self::Other, '462999' => self::Events,
        '463999' => self::Events, '466361' => self::Coaching, '482158' => self::Other, '512899' => self::Coaching,
        '534985' => self::Coaching, '538481' => self::Other, '547961' => self::Coaching, '547962' => self::Coaching,
        '547964' => self::Coaching, '571129' => self::Other, '607048' => self::Coaching, '611264' => self::Coaching,
        '615803' => self::Other, '615804' => self::Other, '617577' => self::Coaching, '617578' => self::Coaching,
        '617579' => self::Coaching, '627090' => self::Events, '634985' => self::Coaching, '637708' => self::Coaching,
        '637709' => self::Coaching, '653779' => self::Coaching, '653780' => self::Coaching, '653781' => self::Coaching,
        '653782' => self::Coaching, '653783' => self::Coaching, '653784' => self::Coaching, '655575' => self::Coaching,
        '655579' => self::Coaching, '655581' => self::Coaching, '697367' => self::Coaching, '714823' => self::Coaching,
        '733615' => self::Coaching, '745056' => self::Coaching, '772554' => self::Coaching, '772555' => self::Coaching,
        '7950567' => self::Coaching, '820217' => self::Events, '842826' => self::Coaching, '842827' => self::Coaching,
        '845030' => self::Coaching, '852138' => self::Other, '861061' => self::Coaching, '863490' => self::Coaching,
        '863491' => self::Coaching, '863492' => self::Coaching, '863493' => self::Coaching, '863494' => self::Coaching,
        '863499' => self::Coaching, '863589' => self::Coaching, '863590' => self::Coaching, '863591' => self::Coaching,
        '863592' => self::Coaching, '863593' => self::Coaching, '863599' => self::Coaching, '863689' => self::Coaching,
        '863999' => self::Coaching, '873689' => self::Upsell, '874766' => self::EventCommunity, '879636' => self::Events,
        '894640' => self::Coaching, '934487' => self::Upsell, '9363488' => self::Upsell, '951929' => self::Upsell,
        '955287' => self::Coaching, '957924' => self::Coaching, '969263' => self::Other, '987999' => self::Coaching,
    ];

    public static function streamFor(?string $sku): ?string
    {
        return $sku === null ? null : (self::PerSku[trim($sku)] ?? null);
    }
}
