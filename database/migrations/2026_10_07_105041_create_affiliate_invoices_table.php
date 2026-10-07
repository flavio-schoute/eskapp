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
        Schema::create('affiliate_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mollie_sales_invoice_id')->unique();
            $table->string('invoice_number')->nullable();
            $table->string('status');
            $table->date('period');
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->string('sent_to');
            $table->string('pdf_url', 2048)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_invoices');
    }
};
