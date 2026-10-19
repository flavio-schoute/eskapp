<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order lines from a manually uploaded Plug&Pay export remember their import, so undoing the import removes them.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('finance_orders', function (Blueprint $table) {
            $table->foreignId('import_id')->nullable()->after('external_id')->constrained('finance_imports')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('finance_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_id');
        });
    }
};
