<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\ContractController;
use App\Http\Controllers\Admin\ContractTemplateController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\Auth\InvitationController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\Auth\PasswordResetController;
use App\Http\Controllers\Admin\Auth\TwoFactorController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin back office  (prefix: /admin, names: admin.*)
|--------------------------------------------------------------------------
*/

// ---- Guests ---------------------------------------------------------
Route::middleware('guest:admin')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:admin-login');

    Route::get('two-factor/challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('two-factor/challenge', [TwoFactorController::class, 'verify'])->middleware('throttle:admin-2fa');

    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:admin-login')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:admin-login')->name('password.update');

    Route::get('invitation/{admin}', [InvitationController::class, 'show'])->middleware('signed')->name('invitation.show');
    Route::post('invitation/{admin}', [InvitationController::class, 'store'])->middleware('signed')->name('invitation.store');
});

// ---- Signed in, two-factor may still be pending --------------------
Route::middleware('auth:admin')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('two-factor/setup', [TwoFactorController::class, 'confirm'])->middleware('throttle:admin-2fa');
    Route::get('two-factor/recovery-codes', [TwoFactorController::class, 'recovery'])->name('two-factor.recovery');
});

// ---- Signed in with two-factor --------------------------------------
Route::middleware(['auth:admin', 'admin.2fa'])->group(function () {
    Route::get('/', DashboardController::class)->middleware('can:menu.dashboard')->name('dashboard');

    Route::get('account', [AccountController::class, 'show'])->name('account');
    Route::put('account/password', [AccountController::class, 'updatePassword'])->name('account.password');
    Route::post('account/recovery-codes', [AccountController::class, 'regenerateRecoveryCodes'])->name('account.recovery-codes');

    Route::middleware('can:menu.admins')->group(function () {
        Route::get('admins', [AdminUserController::class, 'index'])->name('admins.index');
        Route::post('admins', [AdminUserController::class, 'store'])->name('admins.store');
        Route::put('admins/{admin}', [AdminUserController::class, 'update'])->name('admins.update');
        Route::post('admins/{admin}/toggle-active', [AdminUserController::class, 'toggleActive'])->name('admins.toggle');
        Route::post('admins/{admin}/resend-invite', [AdminUserController::class, 'resendInvite'])->name('admins.resend');
        Route::post('admins/{admin}/reset-two-factor', [AdminUserController::class, 'resetTwoFactor'])->name('admins.reset-2fa');

        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('roles/access', [RoleController::class, 'syncAccess'])->name('roles.access');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    Route::middleware('can:menu.coupons')->group(function () {
        Route::get('coupons', [CouponController::class, 'index'])->name('coupons.index');
        Route::post('coupons', [CouponController::class, 'store'])->name('coupons.store');
        Route::put('coupons/{coupon}', [CouponController::class, 'update'])->name('coupons.update');
    });

    Route::middleware('can:menu.contracts')->group(function () {
        Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::get('contracts/{contract:uuid}/pdf', [ContractController::class, 'pdf'])->name('contracts.pdf');
        Route::get('contract-templates/{template}', [ContractTemplateController::class, 'show'])->name('contracts.templates.show');
        Route::post('contract-templates/{template}/new-version', [ContractTemplateController::class, 'duplicate'])->name('contracts.templates.duplicate');
        Route::get('contract-templates/{template}/edit', [ContractTemplateController::class, 'edit'])->name('contracts.templates.edit');
        Route::put('contract-templates/{template}', [ContractTemplateController::class, 'update'])->name('contracts.templates.update');
        Route::post('contract-templates/{template}/publish', [ContractTemplateController::class, 'publish'])->name('contracts.templates.publish');
        Route::delete('contract-templates/{template}', [ContractTemplateController::class, 'destroy'])->name('contracts.templates.destroy');
    });

    Route::middleware('can:menu.settings')->group(function () {
        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('settings/signature', [SettingsController::class, 'updateSignature'])->name('settings.signature.update');
        Route::get('settings/signature/image', [SettingsController::class, 'signature'])->name('settings.signature');
        Route::delete('settings/stripe/live', [SettingsController::class, 'clearStripeLive'])->name('settings.stripe.clear-live');
        Route::put('settings/{group}', [SettingsController::class, 'update'])
            ->whereIn('group', ['company', 'pricing', 'renewal', 'stripe', 'email', 'security', 'tax'])
            ->name('settings.update');
    });
});
