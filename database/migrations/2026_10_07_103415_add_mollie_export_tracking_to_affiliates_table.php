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
        Schema::table('affiliates', function (Blueprint $table) {
            $table->timestamp('mollie_exported_at')->nullable()->after('mollie_customer_id');
            $table->string('mollie_export_fingerprint', 64)->nullable()->after('mollie_exported_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn(['mollie_exported_at', 'mollie_export_fingerprint']);
        });
    }
};
