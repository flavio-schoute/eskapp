<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the Mollie CSV export tracking and the panel settings table: Mollie customers are now created
 * automatically and invoices always go through the Mollie API.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn(['mollie_exported_at', 'mollie_export_fingerprint']);
        });

        Schema::dropIfExists('app_settings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->timestamp('mollie_exported_at')->nullable()->after('mollie_customer_id');
            $table->string('mollie_export_fingerprint', 64)->nullable()->after('mollie_exported_at');
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }
};
