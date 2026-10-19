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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plug_and_pay_product_id')->unique();
            $table->string('title');
            $table->string('public_title')->nullable();
            $table->string('type')->nullable();
            $table->string('group')->nullable();
            $table->string('sku')->nullable();
            $table->boolean('in_use')->default(false);
            $table->unsignedInteger('orders_count')->default(0);
            $table->date('last_ordered_at')->nullable();
            $table->timestamp('plug_and_pay_created_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
