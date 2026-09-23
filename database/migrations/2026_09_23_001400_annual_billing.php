<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Annual prepay: billing interval and discount frozen on each agreement. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('billing_interval', 10)->default('month')->after('per_agent_fee_cents')->comment('month | year');
            $table->decimal('annual_discount_percent', 5, 2)->default(0)->after('billing_interval');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn(['billing_interval', 'annual_discount_percent']));
    }
};
