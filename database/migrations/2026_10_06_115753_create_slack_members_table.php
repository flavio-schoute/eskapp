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
        Schema::create('slack_members', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slack_user_id')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('slack_members')->insert(collect([
            'Abel Jansen' => 'U09F6D25ABT',
            'Ben Durinck' => 'U0B774NFKCL',
            'Flavio' => 'U0926AJE52T',
            'Gairo' => 'U073Z843CGP',
            'Michiel' => 'U09JBP2T6J1',
            'Raimon Wegkamp' => 'U0C0Z8HKJTD',
        ])->map(fn (string $slackUserId, string $name): array => [
            'name' => $name,
            'slack_user_id' => $slackUserId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->values()->all());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_members');
    }
};
