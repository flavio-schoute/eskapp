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
            $table->string('invoice_company_name')->nullable()->after('payment_method');
            $table->string('invoice_address_line_1')->nullable()->after('invoice_company_name');
            $table->string('invoice_address_line_2')->nullable()->after('invoice_address_line_1');
            $table->string('invoice_postal_code', 20)->nullable()->after('invoice_address_line_2');
            $table->string('invoice_city')->nullable()->after('invoice_postal_code');
            $table->string('invoice_region')->nullable()->after('invoice_city');
            $table->char('invoice_country', 2)->nullable()->after('invoice_region');
            $table->string('invoice_contact_person')->nullable()->after('invoice_country');
            $table->string('invoice_phone', 50)->nullable()->after('invoice_contact_person');
            $table->string('invoice_email')->nullable()->after('invoice_phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_company_name',
                'invoice_address_line_1',
                'invoice_address_line_2',
                'invoice_postal_code',
                'invoice_city',
                'invoice_region',
                'invoice_country',
                'invoice_contact_person',
                'invoice_phone',
                'invoice_email',
            ]);
        });
    }
};
