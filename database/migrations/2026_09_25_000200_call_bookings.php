<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Discovery calls booked through the GoHighLevel calendar, with the coupon and source they came from. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('ghl_appointment_id')->nullable()->unique();
            $table->string('ghl_contact_id')->nullable();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable();
            $table->string('client_timezone', 60)->nullable();
            $table->string('name', 160)->nullable();
            $table->string('email', 160)->nullable()->index();
            $table->string('phone', 40)->nullable();
            $table->string('company_name', 160)->nullable();
            $table->string('coupon_code', 40)->nullable()->index();
            $table->string('source', 160)->nullable();
            $table->json('utm')->nullable();
            $table->string('meeting_link')->nullable();
            $table->string('status', 20)->default('scheduled')->index()->comment('scheduled | completed | no_show | cancelled | onboarded');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('admin_notes', 1000)->nullable();
            $table->json('last_payload')->nullable()->comment('Last webhook body from GoHighLevel, for troubleshooting');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_bookings');
    }
};
