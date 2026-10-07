<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 12: the details step is split in two, so a customer (lead) can exist with only
 * "About you" filled in. Tracks where people stopped, the follow-up email sequence, and
 * the GoHighLevel contact the lead is synced to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('street')->nullable()->change();
            $table->string('city', 100)->nullable()->change();
            $table->char('state_code', 2)->nullable()->change();
            $table->string('zip', 10)->nullable()->change();
            $table->string('onboarding_stage', 20)->nullable()->after('status')
                ->comment('Next unfinished screen: brokerage | agreement | payment | done');
            $table->unsignedTinyInteger('follow_up_step')->default(0)->after('deposit_reminded_at');
            $table->timestamp('follow_up_last_at')->nullable()->after('follow_up_step');
            $table->timestamp('follow_up_unsubscribed_at')->nullable()->after('follow_up_last_at');
            $table->string('ghl_contact_id')->nullable()->after('stripe_agent_item_id');
            $table->index(['status', 'onboarding_stage']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['status', 'onboarding_stage']);
            $table->dropColumn(['onboarding_stage', 'follow_up_step', 'follow_up_last_at', 'follow_up_unsubscribed_at', 'ghl_contact_id']);
        });
    }
};
