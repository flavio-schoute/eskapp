<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The e-Skool BV set-up from the Finance (Next.js) app: categories with their codes, revenue streams, accounts, the
 * agreed rules and the revenue stream per product. The transaction history follows later in a separate migration.
 */
return new class extends Migration
{
    /**
     * Groups: [name, kind, color, below operating, [[subcategory, code, vat], ...]].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: bool, 4: list<array{0: string, 1: string, 2: float|null}>}>
     */
    private const Categories = [
        ['Fiscale Kosten', 'expense', '#6B6760', true, []],
        ['Marketing', 'expense', '#C45A35', false, [
            ['Content filmen', '108', 0.21], ['Marketing gezicht 1', '111', 0.21], ['Adspend Google', '100', 0.21],
            ['Adspend Facebook', '101', 0.21], ['Adspend Influencer marketing', '102', 0.21], ['Marketing manager', '103', 0.21],
            ['Marketing kosten overig', '104', 0.21], ['Branding kosten', '105', 0.21], ['Content manager', '106', 0.21],
            ['Content editors', '107', 0.21], ['Content gear', '109', 0.21], ['Content kosten overig', '110', 0.21],
        ]],
        ['Sales', 'expense', '#2563EB', false, [
            ['Sales manager', '200', 0.21], ['Setter manager', '201', 0.21], ['Setter kosten', '202', 0.21],
            ['Closer kosten', '203', 0.21], ['Beller kosten', '204', 0.21], ['Affiliate commissie', '205', 0.21],
        ]],
        ['Delivery', 'expense', '#2D8659', false, [
            ['Delivery member kosten', '306', 0.21], ['Delivery manager', '300', 0.21], ['Delivery coaches', '301', 0.21],
            ['Delivery educatie', '302', 0.21], ['Delivery event-sales', '303', 0.21], ['Delivery event-community', '304', 0.21],
            ['Affiliate Winstverdeling', '305', 0.21],
        ]],
        ['Finance', 'expense', '#7C3AED', false, [['Finance', '400', 0.21]]],
        ['Tools & Software', 'expense', '#0891B2', false, [['Tools & Software', '500', 0.21]]],
        ['Overhead', 'expense', '#6B6760', false, [
            ['Kantoor', '610', 0.21], ['Zakelijke reiskosten', '611', 0.21], ['Fiscaal advies', '600', 0.21],
            ['Klantenservice/admin', '601', 0.21], ['Tech & backend', '602', 0.21], ['Juridische kosten', '603', 0.21],
            ['Incassobureau kosten', '604', 0.21], ['Algemene kosten', '605', 0.21], ['Coaching & training', '606', 0.21],
            ['Team building', '607', 0.21], ['Goede doelen', '608', 0.21], ['Bonus team', '609', 0.21],
        ]],
        ['Refunds', 'expense', '#C0392B', false, [['Refund kosten', '700', 0.21]]],
        ['Omzet (bank)', 'revenue', '#A39E93', false, [['Affiliate inkomsten (bij-schrijving)', '454545', null]]],
        ['Belastingen', 'tax', '#A39E93', false, [['Btw-afdracht', 'TAX-BTW', null], ['Vennootschapsbelasting', 'TAX-VPB', null]]],
        ['Financiering', 'financing', '#A39E93', false, [['Leningen (Bridgefund e.d.)', 'FIN-LOAN', null], ['Rekening-courant aandeelhouders', 'FIN-RC', null]]],
        ['Interne overboekingen', 'transfer', '#A39E93', false, [
            ['Payment provider uitbetalingen', 'TR-PSP', null], ['Creditcard-afrekening', 'TR-CC', null], ['Eigen rekeningen / sparen', 'TR-OWN', null],
        ]],
    ];

    /**
     * @var list<array{0: string, 1: string}>
     */
    private const Streams = [
        ['Coaching sales', '#2563EB'], ['Incasso inkomsten', '#0E7490'], ['Upsell sales', '#16A34A'], ['Event community memberships', '#D97706'],
        ['Event sales', '#DB2777'], ['Affiliate inkomsten', '#15803D'], ['e-Scale', '#7C3AED'], ['Overig / Intern', '#6B6760'],
    ];

    /**
     * Revenue stream per Plug&Pay SKU, as set in the Finance app.
     *
     * @var array<string, string>
     */
    private const StreamPerSku = [
        '888999' => 'Coaching sales', '863488' => 'Coaching sales', '863989' => 'Coaching sales', '795055' => 'Coaching sales',
        '795056' => 'Coaching sales', '963989' => 'Coaching sales', '887999' => 'Coaching sales', '261411' => 'Coaching sales',
        '530222' => 'Coaching sales', '1063488' => 'Upsell sales', '432450' => 'Upsell sales', '103963488' => 'Upsell sales',
        '432451' => 'Upsell sales', '432448' => 'Upsell sales', '259254' => 'Event community memberships', '778949' => 'Event sales',
        '778947' => 'Event sales', '360758' => 'Overig / Intern', '512225' => 'Overig / Intern', '944618' => 'Overig / Intern',
        '409806' => 'Affiliate inkomsten',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('finance_categories')->exists()) {
            return;
        }

        $now = now();
        $ids = [];

        foreach (self::Categories as $groupOrder => [$name, $kind, $color, $below, $children]) {
            $groupId = DB::table('finance_categories')->insertGetId([
                'name' => $name, 'kind' => $kind, 'color' => $color, 'below_operating' => $below,
                'default_vat_rate' => $kind === 'expense' ? 0.21 : null, 'sort_order' => ($groupOrder + 1) * 10,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach ($children as $childOrder => [$childName, $code, $vat]) {
                $ids[$code] = DB::table('finance_categories')->insertGetId([
                    'parent_id' => $groupId, 'name' => $childName, 'code' => $code, 'kind' => $kind, 'color' => $color,
                    'default_vat_rate' => $vat, 'sort_order' => ($childOrder + 1) * 10, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $streams = [];

        foreach (self::Streams as $order => [$name, $color]) {
            $streams[$name] = DB::table('finance_revenue_streams')->insertGetId(['name' => $name, 'color' => $color, 'sort_order' => ($order + 1) * 10, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach (self::StreamPerSku as $sku => $stream) {
            DB::table('products')->where('sku', $sku)->update(['revenue_stream_id' => $streams[$stream]]);
        }

        $accounts = [];

        foreach ([['Amex', null, 'creditcard', 'amex'], ['Handmatig (uit sheet)', null, 'manual', null], ['ING zakelijk', 'NL03INGB0109434978', 'bank', 'ing-nl'], ['Rabobank (2024)', 'NL45RABO0317820036', 'bank', 'rabobank']] as [$name, $iban, $type, $format]) {
            $accounts[$name] = DB::table('finance_accounts')->insertGetId(['name' => $name, 'iban' => $iban, 'type' => $type, 'bank_format' => $format, 'created_at' => $now, 'updated_at' => $now]);
        }

        $inbound = ['field' => 'direction', 'op' => 'equals', 'value' => 'in'];
        $counterparty = fn (string $op, string $value): array => ['field' => 'counterparty', 'op' => $op, 'value' => $value];

        // The rules agreed in the Finance app (29 Sept 2026). Payment providers pay out customer money that is
        // already revenue through the Plug&Pay orders, so those payouts are internal transfers.
        $rules = [
            ['Mollie-uitbetaling', 'TR-PSP', null, [$inbound, $counterparty('contains', 'mollie')]],
            ['Collect4u-uitbetaling', 'TR-PSP', null, [$inbound, $counterparty('starts_with', 'collect4u')]],
            ['GGN-uitbetaling (incasso)', 'TR-PSP', null, [$inbound, $counterparty('starts_with', 'ggn mastering')]],
            ['Stripe-uitbetaling', 'TR-PSP', null, [$inbound, $counterparty('contains', 'stripe')]],
            ['PayPro-uitbetaling', 'TR-PSP', null, [$inbound, $counterparty('starts_with', 'paypro')]],
            ['Oranje Spaarrekening', 'TR-OWN', null, [$counterparty('contains', 'oranje spaarrekening')]],
            ['Bridgefund lening', 'FIN-LOAN', null, [$counterparty('contains', 'bridgefund')]],
            ['Belastingdienst', 'TAX-BTW', null, [$counterparty('starts_with', 'belastingdienst')]],
            // Facebook Ireland invoices with reverse-charge VAT: the bank amount is the cost excl. VAT.
            ['Facebook Ads (bank)', '101', 0.0, [$counterparty('starts_with', 'facebook payments')]],
            // Points at the card Facebook is paid with; left inactive until the right account is chosen.
            ['Facebook Ads (creditcard)', '101', 0.0, [['field' => 'account_id', 'op' => 'equals', 'value' => (string) $accounts['Amex']]], false],
        ];

        foreach ($rules as $order => $rule) {
            [$name, $code, $vat, $conditions] = $rule;

            DB::table('finance_rules')->insert([
                'name' => $name, 'priority' => ($order + 1) * 10, 'active' => $rule[4] ?? true, 'conditions' => json_encode($conditions),
                'action' => json_encode(['categoryId' => $ids[$code], 'vatRate' => $vat]), 'source' => 'seed',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('finance_rules')->where('source', 'seed')->delete();
    }
};
