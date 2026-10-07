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
        Schema::create('integration_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('integration');
            $table->string('action');
            $table->text('message');
            $table->string('exception_class')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('last_occurred_at');
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_errors');
    }
};
