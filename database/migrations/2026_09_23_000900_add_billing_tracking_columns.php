<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 billing bookkeeping:
 * - which go-live date the 3-day balance reminder was sent for (so it is sent once, and again if the date moves)
 * - the Stripe subscription item that carries the per-agent quantity
 * - when the customer went live, and the agent-count API token (encrypted)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->date('balance_reminder_for')->nullable()->after('go_live_date');
            $table->string('stripe_agent_item_id')->nullable()->after('stripe_subscription_id');
            $table->timestamp('live_at')->nullable()->after('go_live_date');
            // Needed in plain form to call the customer's RightAlly instance (daily pull); encrypted at rest.
            $table->text('agent_api_token')->nullable()->after('agent_api_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['balance_reminder_for', 'stripe_agent_item_id', 'live_at', 'agent_api_token']);
        });
    }
};
