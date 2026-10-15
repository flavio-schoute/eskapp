<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('affiliate_contracts', function (Blueprint $table) {
            $table->json('fields')->nullable()->after('is_test');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliate_contracts', function (Blueprint $table) {
            $table->dropColumn('fields');
        });
    }
};
