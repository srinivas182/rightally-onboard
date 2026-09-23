<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique()->comment('System key, or custom-* for custom templates');
            $table->boolean('is_system')->default(true)->comment('System templates cannot be deleted');
            $table->string('name');
            $table->string('trigger_description')->nullable();
            $table->string('subject');
            $table->longText('body');
            $table->boolean('cc_team')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_key', 60)->nullable();
            $table->string('to_email');
            $table->json('cc')->nullable();
            $table->string('subject');
            $table->string('provider_message_id')->nullable()->index();
            $table->string('status', 20)->default('queued')->comment('queued | sent | delivered | opened | bounced | failed');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('email_templates');
    }
};
