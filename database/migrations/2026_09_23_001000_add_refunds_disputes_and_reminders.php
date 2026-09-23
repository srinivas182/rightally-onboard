<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7:
 * - refunds and chargebacks on payments (revenue = amount minus refunded; open disputes excluded)
 * - follow-up reminders for clients who signed but haven't paid the deposit
 * - tax collected on each invoice (Stripe Tax)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('refunded_cents')->default(0)->after('fee_cents');
            $table->string('stripe_dispute_id')->nullable()->after('stripe_charge_id');
            $table->string('dispute_status', 30)->nullable()->after('stripe_dispute_id');
            $table->timestamp('dispute_due_by')->nullable()->after('dispute_status');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedTinyInteger('deposit_reminders_sent')->default(0)->after('balance_reminder_for');
            $table->timestamp('deposit_reminded_at')->nullable()->after('deposit_reminders_sent');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('stripe_tax_calculation_id')->nullable()->after('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['refunded_cents', 'stripe_dispute_id', 'dispute_status', 'dispute_due_by']));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['deposit_reminders_sent', 'deposit_reminded_at']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('stripe_tax_calculation_id'));
    }
};
