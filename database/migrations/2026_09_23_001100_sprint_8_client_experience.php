<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8:
 * - legal pages (privacy policy, terms of use), editable in admin
 * - card expiry, for the "card expiring" warning
 * - when the client was last told about an agent-count change
 * - who was asked to sign, when someone else signs
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique()->comment('privacy | terms');
            $table->string('title');
            $table->longText('body_html');
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedTinyInteger('card_exp_month')->nullable()->after('payment_method_label');
            $table->unsignedSmallInteger('card_exp_year')->nullable()->after('card_exp_month');
            $table->string('card_expiry_warned_for', 7)->nullable()->after('card_exp_year')->comment('YYYY-MM already warned about');
            $table->timestamp('agents_notified_at')->nullable()->after('agent_count_synced_at');
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('signer_email')->nullable()->after('client_title');
            $table->timestamp('signature_requested_at')->nullable()->after('signer_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_pages');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['card_exp_month', 'card_exp_year', 'card_expiry_warned_for', 'agents_notified_at']));
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn(['signer_email', 'signature_requested_at']));
    }
};
