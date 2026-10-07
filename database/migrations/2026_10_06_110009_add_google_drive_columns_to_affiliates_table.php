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
            $table->string('google_drive_folder_id')->nullable()->after('notes');
            $table->string('agreement_file_name')->nullable()->after('google_drive_folder_id');
            $table->string('agreement_drive_file_id')->nullable()->after('agreement_file_name');
            $table->string('agreement_drive_url')->nullable()->after('agreement_drive_file_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn([
                'google_drive_folder_id',
                'agreement_file_name',
                'agreement_drive_file_id',
                'agreement_drive_url',
            ]);
        });
    }
};
