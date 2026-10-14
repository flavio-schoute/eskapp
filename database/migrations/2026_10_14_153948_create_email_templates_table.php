<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('name');
            $table->string('language', 5);
            $table->string('subject');
            $table->text('body');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['type', 'language', 'is_default']);
        });

        DB::table('email_templates')->insert([
            [
                'type' => 'mollie_invoice',
                'name' => 'Standaard (Nederlands)',
                'language' => 'nl',
                'subject' => 'Factuur affiliate commissie {month}',
                'body' => "Beste {contact_person},\n\nIn de bijlage vind je de factuur voor de affiliate commissie van {month}.\n\nMet vriendelijke groet,\ne-Skool",
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type' => 'mollie_invoice',
                'name' => 'Default (English)',
                'language' => 'en',
                'subject' => 'Invoice affiliate commission {month}',
                'body' => "Dear {contact_person},\n\nPlease find attached the invoice for the affiliate commission of {month}.\n\nKind regards,\ne-Skool",
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
