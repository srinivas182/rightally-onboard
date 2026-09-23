<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 10:
 * - approvals: a second admin approves early terminations and large refunds/credits
 * - credits applied to the next monthly charge
 * - subscription pauses (term extended by the pause)
 * - email bounces reported by Brevo
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->string('action', 30)->comment('early_termination | refund | credit');
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->json('payload');
            $table->string('reason', 250);
            $table->foreignId('requested_by')->constrained('admins');
            $table->string('status', 20)->default('pending')->index()->comment('pending | approved | rejected | failed');
            $table->foreignId('decided_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 250)->nullable();
            $table->text('result')->nullable();
            $table->timestamps();
        });
        Schema::create('credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason', 250);
            $table->string('stripe_balance_transaction_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedTinyInteger('months');
            $table->string('reason', 250);
            $table->timestamp('resumed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedTinyInteger('paused_months')->default(0)->after('term_months');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->date('paused_until')->nullable()->after('suspended_at');
            $table->timestamp('email_bounced_at')->nullable()->after('email');
            $table->string('email_bounce_reason')->nullable()->after('email_bounced_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['paused_until', 'email_bounced_at', 'email_bounce_reason']));
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('paused_months'));
        Schema::dropIfExists('pauses');
        Schema::dropIfExists('credits');
        Schema::dropIfExists('approvals');
    }
};
