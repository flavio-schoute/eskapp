<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Puts the subcategories in the same order as in the Finance app and matches the group colours.
 */
return new class extends Migration
{
    /**
     * Subcategory codes per group, in the Finance app's order.
     *
     * @var array<string, list<string>>
     */
    private const Order = [
        'Marketing' => ['108', '111', '100', '101', '102', '103', '104', '105', '106', '107', '109', '110'],
        'Delivery' => ['306', '300', '301', '302', '303', '304', '305'],
        'Overhead' => ['610', '611', '600', '601', '602', '603', '604', '605', '606', '607', '608', '609'],
    ];

    /**
     * @var array<string, string>
     */
    private const Colors = ['Sales' => '#2563EB', 'Tools & Software' => '#0891B2'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::Order as $codes) {
            foreach ($codes as $index => $code) {
                DB::table('finance_categories')->where('code', $code)->update(['sort_order' => ($index + 1) * 10]);
            }
        }

        foreach (self::Colors as $group => $color) {
            $groupId = DB::table('finance_categories')->whereNull('parent_id')->where('name', $group)->value('id');

            DB::table('finance_categories')->where('id', $groupId)->orWhere('parent_id', $groupId)->update(['color' => $color]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
