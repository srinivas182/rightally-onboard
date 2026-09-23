<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned agreement templates and signed agreements.
 *
 * A signed contract stores every price as it was at signing ("snapshot"),
 * so later changes in Settings never alter an agreement already signed.
 * Money is stored in cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->comment('initial | renewal');
            $table->string('version', 20);
            $table->string('title');
            $table->longText('body_html');
            $table->boolean('is_active')->default(false);
            $table->timestamp('published_at')->nullable()->comment('Published templates are read-only');
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->unique(['type', 'version']);
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 20)->unique()->comment('RA-YYYY-NNNN');
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('previous_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('type', 20)->comment('initial | renewal');
            $table->string('status', 20)->default('draft')->index();

            // Price snapshot (cents)
            $table->unsignedBigInteger('setup_fee_cents')->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coupon_code', 40)->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('implementation_fee_cents')->default(0);
            $table->decimal('deposit_percent', 5, 2)->default(10);
            $table->unsignedBigInteger('deposit_cents')->default(0);
            $table->unsignedBigInteger('balance_cents')->default(0);
            $table->unsignedBigInteger('platform_fee_cents');
            $table->unsignedBigInteger('per_agent_fee_cents');
            $table->unsignedInteger('min_agents')->default(5);
            $table->unsignedInteger('agent_count');
            $table->unsignedSmallInteger('term_months')->default(12);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            // Company side (from Settings at signing)
            $table->string('company_legal_name');
            $table->string('company_dba')->nullable();
            $table->string('company_address');
            $table->string('company_signatory_name');
            $table->string('company_signatory_title');
            $table->string('company_signature_path')->nullable();

            // Client signature and E-SIGN evidence
            $table->string('client_typed_name')->nullable();
            $table->string('client_title')->nullable();
            $table->string('client_signature_path')->nullable()->comment('Drawn signature PNG, private disk');
            $table->timestamp('esign_consent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signer_ip', 45)->nullable();
            $table->text('signer_user_agent')->nullable();

            $table->longText('rendered_html')->nullable()->comment('Exact text the client saw');
            $table->string('pdf_path')->nullable();
            $table->char('document_sha256', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('contract_templates');
    }
};
