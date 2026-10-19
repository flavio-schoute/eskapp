<?php

use App\Support\Finance\ProductStreams;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Links every product to its revenue stream by SKU, as in the Finance app. Products synced later get it too.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $streams = DB::table('finance_revenue_streams')->pluck('id', 'name');

        foreach (ProductStreams::PerSku as $sku => $stream) {
            if (isset($streams[$stream])) {
                DB::table('products')->where('sku', $sku)->update(['revenue_stream_id' => $streams[$stream]]);
            }
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
