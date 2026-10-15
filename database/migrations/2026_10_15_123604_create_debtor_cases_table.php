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
        Schema::create('debtor_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plug_and_pay_order_id')->unique();
            $table->string('type');
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('product')->nullable();
            $table->decimal('amount', 10, 2);
            $table->decimal('amount_with_tax', 10, 2);
            $table->string('customer_name')->nullable();
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('payment_url', 2048)->nullable();
            $table->string('invoice_url', 2048)->nullable();
            $table->string('mollie_transaction_id')->nullable();
            $table->string('mollie_customer_id')->nullable();
            $table->string('plug_and_pay_debtor_status')->nullable();
            $table->unsignedTinyInteger('plug_and_pay_debtor_step')->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debtor_cases');
    }
};
