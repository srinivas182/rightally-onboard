<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices (what is owed) and payments (what actually moved), plus the
 * Stripe webhook log that makes event handling idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique()->comment('INV-YYYY-NNNN');
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30)->comment('deposit | balance | monthly | early_termination');
            $table->string('status', 20)->default('scheduled')->index()->comment('scheduled | processing | paid | failed | void');
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->char('currency', 3)->default('usd');
            $table->unsignedInteger('agents_billed')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('due_on')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('stripe_invoice_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('hosted_invoice_url', 500)->nullable();
            $table->timestamp('payment_link_sent_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'type']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('stripe_charge_id')->nullable()->unique();
            $table->string('method', 20)->comment('card | us_bank_account');
            $table->string('method_label', 60)->nullable();
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('fee_cents')->nullable()->comment('Stripe processing fee');
            $table->string('status', 20)->index()->comment('processing | succeeded | failed | refunded');
            $table->timestamp('settled_at')->nullable()->index()->comment('Counts as revenue from this time');
            $table->timestamps();
        });

        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type', 80)->index();
            $table->boolean('livemode')->default(false);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
    }
};
