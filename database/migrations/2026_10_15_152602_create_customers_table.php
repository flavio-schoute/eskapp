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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->string('company')->nullable();
            $table->string('phone')->nullable();
            $table->string('street')->nullable();
            $table->string('house_number')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('vat_number')->nullable();
            $table->unsignedBigInteger('plug_and_pay_customer_id')->nullable()->index();
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('open_amount', 12, 2)->default(0);
            $table->timestamp('customer_since')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->json('mollie_customer_ids')->nullable();
            $table->string('mollie_name')->nullable();
            $table->string('mollie_email')->nullable();
            $table->string('match_status')->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
