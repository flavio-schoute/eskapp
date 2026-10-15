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
            $table->string('esignatures_signer_id')->nullable()->after('esignatures_contract_id');
            $table->timestamp('reminded_at')->nullable()->after('signed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliate_contracts', function (Blueprint $table) {
            $table->dropColumn(['esignatures_signer_id', 'reminded_at']);
        });
    }
};
