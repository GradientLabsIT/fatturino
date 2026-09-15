<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->date('date')->index();
            $table->unsignedSmallInteger('fiscal_year')->index();
            $table->string('kind')->default('monthly_summary')->index();
            $table->string('status')->default('draft')->index();
            $table->string('transmission_channel')->default('register_only');
            $table->string('description');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('transaction_count')->default(1);
            $table->string('source')->default('manual');
            $table->string('external_reference')->nullable()->unique();

            $table->bigInteger('total_net')->default(0)->comment('Cents; signed for net refunds');
            $table->bigInteger('total_vat')->default(0)->comment('Cents; signed for net refunds');
            $table->bigInteger('total_gross')->default(0)->comment('Cents; signed for net refunds');
            $table->bigInteger('cash_payment_amount')->default(0);
            $table->bigInteger('electronic_payment_amount')->default(0);
            $table->bigInteger('uncollected_amount')->default(0);

            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable()->unique();
            $table->string('provider_status')->nullable();
            $table->string('provider_document_number')->nullable();
            $table->json('provider_payload')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->string('item_type')->default('service');
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->bigInteger('unit_price_gross')->default(0);
            $table->string('vat_rate_code');
            $table->string('vat_nature')->nullable();
            $table->string('country_group')->nullable();
            $table->bigInteger('net_amount')->default(0);
            $table->bigInteger('vat_amount')->default(0);
            $table->bigInteger('gross_amount')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_lines');
        Schema::dropIfExists('receipts');
    }
};
