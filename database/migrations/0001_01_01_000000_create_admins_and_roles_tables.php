<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Back-office admins, their roles and the menus each role can open.
 *
 * Roles are deliberately simple: one role per admin, and a role grants
 * access to whole menus (config/rightally.php "menus").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false)->comment('System roles cannot be renamed or deleted');
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 60)->comment('Menu key from config/rightally.php');
            $table->primary(['role_id', 'permission']);
        });

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable()->comment('Null until an invited admin accepts');
            $table->text('two_factor_secret')->nullable()->comment('Encrypted');
            $table->text('two_factor_recovery_codes')->nullable()->comment('Encrypted JSON of hashed codes');
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('invited_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('admins');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
