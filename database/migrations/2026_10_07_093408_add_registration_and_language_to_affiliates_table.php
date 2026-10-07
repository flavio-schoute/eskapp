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
            $table->string('invoice_kvk_number', 20)->nullable()->after('invoice_company_name');
            $table->string('invoice_vat_number', 20)->nullable()->after('invoice_kvk_number');
            $table->string('invoice_language', 5)->nullable()->after('invoice_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn(['invoice_kvk_number', 'invoice_vat_number', 'invoice_language']);
        });
    }
};
