<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 9:
 * - custom quotes (negotiated pricing through a one-time onboarding link)
 * - go-live days frozen on each agreement (quotes can change it)
 * - outgoing webhooks to CRMs/Zapier and to customers' RightAlly sites
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->string('label', 120)->comment('Internal name, e.g. the prospect');
            $table->string('company_name', 160)->nullable();
            $table->string('email', 160)->nullable();
            $table->unsignedBigInteger('setup_fee_cents');
            $table->decimal('deposit_percent', 5, 2);
            $table->unsignedBigInteger('platform_fee_cents');
            $table->unsignedBigInteger('per_agent_fee_cents');
            $table->unsignedInteger('min_agents');
            $table->unsignedSmallInteger('go_live_days');
            $table->string('note', 500)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete()->comment('Set when used');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('quote_id')->nullable()->after('coupon_id')->constrained()->nullOnDelete();
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedSmallInteger('go_live_days')->nullable()->after('term_months');
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('url', 500);
            $table->text('secret')->comment('Encrypted; signs every delivery');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->nullable()->constrained()->cascadeOnDelete()->comment('Null = the customer’s own RightAlly site');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 60)->index();
            $table->string('url', 500);
            $table->json('payload');
            $table->string('status', 20)->default('pending')->comment('pending | delivered | failed');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('go_live_days'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropConstrainedForeignId('quote_id'));
        Schema::dropIfExists('quotes');
    }
};
