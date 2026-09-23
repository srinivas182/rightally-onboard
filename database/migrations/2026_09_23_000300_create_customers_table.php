<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A brokerage going through onboarding and, later, billing.
 * The uuid is used in client-facing onboarding links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('status', 30)->default('draft')->index();

            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('title', 80);
            $table->string('company_name', 160);
            $table->string('email')->index();
            $table->string('phone_e164', 20);
            $table->string('street');
            $table->string('city', 100);
            $table->char('state_code', 2);
            $table->string('zip', 10);
            $table->char('country_code', 2)->default('US');

            $table->unsignedInteger('agent_count')->default(5)->comment('Agents billed; minimum enforced in code');
            $table->unsignedInteger('agent_count_entered')->nullable()->comment('What the client typed at onboarding');
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();

            $table->date('go_live_date')->nullable()->index();
            $table->string('live_url')->nullable();
            $table->string('live_host')->nullable();
            $table->string('agent_api_token_hash', 64)->nullable()->unique();
            $table->timestamp('agent_count_synced_at')->nullable();

            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('stripe_payment_method_id')->nullable();
            $table->string('payment_method_type', 20)->nullable()->comment('card | us_bank_account');
            $table->string('payment_method_label', 60)->nullable()->comment('e.g. Visa ending 4242');

            $table->string('source', 120)->nullable()->comment('Coupon link, utm_source or referrer');
            $table->json('utm')->nullable();

            $table->timestamp('onboarding_started_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
