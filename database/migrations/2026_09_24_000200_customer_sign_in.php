<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Client sign-in: a password per customer, set from an emailed link. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
            $table->timestamp('password_set_at')->nullable()->after('password');
            $table->rememberToken()->after('password_set_at');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
        });
        Schema::create('customer_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_password_reset_tokens');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['password', 'password_set_at', 'remember_token', 'last_login_at']));
    }
};
