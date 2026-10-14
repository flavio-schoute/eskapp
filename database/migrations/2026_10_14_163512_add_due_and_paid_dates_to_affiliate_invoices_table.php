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
        Schema::table('affiliate_invoices', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable()->after('status');
            $table->timestamp('paid_at')->nullable()->after('due_at');
            $table->timestamp('status_checked_at')->nullable()->after('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliate_invoices', function (Blueprint $table) {
            $table->dropColumn(['due_at', 'paid_at', 'status_checked_at']);
        });
    }
};
