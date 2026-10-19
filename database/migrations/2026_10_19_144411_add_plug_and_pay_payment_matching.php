<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customers who pay by bank transfer: the bank line is matched to its Plug&Pay order (the revenue already counts
 * through the order) and booked on an internal transfer category, so it is never counted twice.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->string('order_ref', 100)->nullable()->after('note')->index();
            $table->string('order_match', 20)->nullable()->after('order_ref')->index();
        });

        $transfers = DB::table('finance_categories')->whereNull('parent_id')->where('name', 'Interne overboekingen')->first();

        if ($transfers && ! DB::table('finance_categories')->where('code', 'TR-PNP')->exists()) {
            $after = DB::table('finance_categories')->where('code', 'TR-PSP')->value('sort_order') ?? 0;

            DB::table('finance_categories')->insert([
                'parent_id' => $transfers->id,
                'name' => 'Klantbetaling per bank (Plug & Pay)',
                'code' => 'TR-PNP',
                'kind' => 'transfer',
                'sort_order' => $after + 1,
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
        DB::table('finance_transactions')->whereIn('category_id', DB::table('finance_categories')->where('code', 'TR-PNP')->select('id'))->update(['category_id' => null, 'status' => 'uncategorized']);
        DB::table('finance_categories')->where('code', 'TR-PNP')->delete();

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropIndex(['order_ref']);
            $table->dropIndex(['order_match']);
            $table->dropColumn(['order_ref', 'order_match']);
        });
    }
};
