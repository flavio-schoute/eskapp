<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Margin per revenue stream (cost categories linked to a stream), ROAS (ad spend categories), monthly budgets and
 * the signals (budgets, subscriptions, anomalies) that were sent to Slack or dismissed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->foreignId('revenue_stream_id')->nullable()->after('below_operating')->constrained('finance_revenue_streams')->nullOnDelete();
            $table->boolean('is_ad_spend')->default(false)->after('revenue_stream_id');
            $table->bigInteger('monthly_budget_cents')->nullable()->after('is_ad_spend');
        });

        Schema::create('finance_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('type')->index();
            $table->text('message');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('finance_categories')->where('name', 'like', 'Adspend%')->update(['is_ad_spend' => true]);

        // The delivery costs that clearly belong to one revenue stream; the rest can be linked under Categorieën.
        foreach (['Delivery coaches' => 'Coaching sales', 'Delivery event-sales' => 'Event sales', 'Delivery event-community' => 'Event community memberships'] as $category => $stream) {
            $streamId = DB::table('finance_revenue_streams')->where('name', $stream)->value('id');

            if ($streamId) {
                DB::table('finance_categories')->where('name', $category)->update(['revenue_stream_id' => $streamId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_alerts');

        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revenue_stream_id');
            $table->dropColumn(['is_ad_spend', 'monthly_budget_cents']);
        });
    }
};
