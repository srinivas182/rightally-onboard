<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History that must survive disputes: agent count changes and renewal notices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_count_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('old_count')->nullable();
            $table->unsignedInteger('new_count');
            $table->string('source', 20)->comment('onboarding | admin | api_push | api_pull');
            $table->foreignId('admin_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('renewal_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->comment('offer | reminder');
            $table->timestamp('sent_at');
            $table->unique(['contract_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_notices');
        Schema::dropIfExists('agent_count_logs');
    }
};
