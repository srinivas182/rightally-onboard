<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Existing clients: quotes that move a live client (already billed in Stripe) onto a signed
 *   agreement with no set-up fee, optional $0 platform fee, per-customer term, first charge date.
 * - Ending service: how and when a customer's service ends, and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->boolean('is_existing_client')->default(false)->after('label');
            $table->unsignedSmallInteger('term_months')->nullable()->after('go_live_days')->comment('Existing clients: 0 = month to month');
            $table->date('first_charge_on')->nullable()->after('term_months');
            $table->string('old_stripe_subscription_id')->nullable()->after('first_charge_on');
            $table->string('stripe_customer_id')->nullable()->after('old_stripe_subscription_id');
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->date('first_charge_on')->nullable()->after('go_live_days');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->string('old_stripe_subscription_id')->nullable()->after('stripe_subscription_id');
            $table->string('end_type', 20)->nullable()->after('cancelled_at')->comment('term_end | period_end | now_fee | now_no_fee');
            $table->string('end_reason', 40)->nullable()->after('end_type');
            $table->string('end_notes', 1000)->nullable()->after('end_reason');
            $table->date('service_ends_on')->nullable()->after('end_notes');
            $table->timestamp('end_requested_at')->nullable()->after('service_ends_on');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['old_stripe_subscription_id', 'end_type', 'end_reason', 'end_notes', 'service_ends_on', 'end_requested_at']));
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('first_charge_on'));
        Schema::table('quotes', fn (Blueprint $t) => $t->dropColumn(['is_existing_client', 'term_months', 'first_charge_on', 'old_stripe_subscription_id', 'stripe_customer_id']));
    }
};
