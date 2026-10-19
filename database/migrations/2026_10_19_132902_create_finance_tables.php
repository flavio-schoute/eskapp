<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance: the books on a cash basis, rebuilt from the e-Skool Finance (Next.js/Supabase) app for one organization.
 * Bank lines are imported from CSV exports and categorized (by rules or by hand); revenue comes from the paid
 * Plug&Pay orders, grouped into revenue streams through their product. All amounts are integer cents.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('iban', 40)->nullable();
            $table->string('type')->default('bank');
            $table->string('bank_format')->nullable();
            $table->json('csv_mapping')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });

        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('finance_categories')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable()->unique();
            $table->string('kind')->default('expense');
            $table->string('fixed_variable')->nullable();
            $table->decimal('default_vat_rate', 4, 3)->nullable();
            $table->boolean('below_operating')->default(false);
            $table->string('color', 7)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });

        Schema::create('finance_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained('finance_accounts')->cascadeOnDelete();
            $table->string('kind');
            $table->string('filename');
            $table->string('file_hash', 64);
            $table->string('format');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_new')->default(0);
            $table->unsignedInteger('rows_duplicate')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_auto')->default(0);
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('finance_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('priority')->default(100)->index();
            $table->boolean('active')->default(true);
            $table->json('conditions');
            $table->json('action');
            $table->unsignedInteger('hit_count')->default(0);
            $table->string('source')->default('manual');
            $table->timestamps();
        });

        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->foreignId('import_id')->nullable()->constrained('finance_imports')->cascadeOnDelete();
            $table->string('dedupe_hash', 64)->unique();
            $table->date('booked_on')->index();
            $table->date('period_on')->nullable();
            $table->bigInteger('amount_cents');
            $table->string('currency', 3)->default('EUR');
            $table->string('counterparty_name', 500)->default('');
            $table->string('counterparty_iban', 40)->nullable();
            $table->text('description');
            $table->string('mutation_code')->nullable();
            $table->string('mutation_type')->nullable();
            $table->bigInteger('balance_after_cents')->nullable();
            $table->json('raw')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->decimal('vat_rate', 4, 3)->nullable();
            $table->bigInteger('amount_ex_vat_cents')->nullable();
            $table->string('status')->default('uncategorized')->index();
            $table->foreignId('rule_id')->nullable()->constrained('finance_rules')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('categorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('categorized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_transaction_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('finance_transactions')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('finance_categories')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->decimal('vat_rate', 4, 3)->nullable();
            $table->bigInteger('amount_ex_vat_cents');
            $table->text('note')->nullable();
        });

        Schema::create('finance_revenue_streams', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('color', 7)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('revenue_stream_id')->nullable()->after('in_use')->constrained('finance_revenue_streams')->nullOnDelete();
        });

        Schema::create('finance_orders', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->unsignedBigInteger('plug_and_pay_order_id')->index();
            $table->string('order_ref');
            $table->unsignedBigInteger('plug_and_pay_product_id')->nullable()->index();
            $table->string('sku')->nullable()->index();
            $table->string('product_name')->default('');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->bigInteger('subtotal_ex_vat_cents');
            $table->bigInteger('vat_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->string('status')->index();
            $table->date('invoiced_on')->nullable();
            $table->date('paid_on')->nullable()->index();
            $table->string('customer_email')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_orders');

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revenue_stream_id');
        });

        Schema::dropIfExists('finance_revenue_streams');
        Schema::dropIfExists('finance_transaction_splits');
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('finance_rules');
        Schema::dropIfExists('finance_imports');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('finance_accounts');
    }
};
