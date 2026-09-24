<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\ApprovalController;
use App\Http\Controllers\Admin\Auth\InvitationController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\Auth\PasswordResetController;
use App\Http\Controllers\Admin\Auth\TwoFactorController;
use App\Http\Controllers\Admin\ContractController;
use App\Http\Controllers\Admin\ContractTemplateController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\QuoteController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\WebhookController;
use App\Http\Controllers\LegalPageController;
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
    Route::post('two-factor/email-code', [TwoFactorController::class, 'sendEmailCode'])->middleware('throttle:10,10')->name('two-factor.email-code');

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

    Route::middleware('can:menu.customers')->prefix('customers')->name('customers.')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::get('export', [CustomerController::class, 'export'])->name('export');
        Route::get('{customer}', [CustomerController::class, 'show'])->name('show');
        Route::put('{customer}/go-live', [CustomerController::class, 'updateGoLive'])->name('go-live');
        Route::put('{customer}/agents', [CustomerController::class, 'updateAgents'])->name('agents');
        Route::put('{customer}/live', [CustomerController::class, 'updateLive'])->name('live');
        Route::put('{customer}/contact', [CustomerController::class, 'updateContact'])->name('contact');
        Route::delete('{customer}', [CustomerController::class, 'destroy'])->name('destroy');
        Route::post('{customer}/resume-link', [CustomerController::class, 'sendResume'])->middleware('throttle:20,1')->name('resume-link');
        Route::post('{customer}/account-link', [CustomerController::class, 'sendAccountLink'])->middleware('throttle:20,1')->name('account-link');
        Route::post('{customer}/token', [CustomerController::class, 'newToken'])->name('token');
        Route::post('{customer}/resend-welcome', [CustomerController::class, 'resendWelcome'])->name('resend-welcome');
        Route::post('{customer}/send-email', [CustomerController::class, 'sendEmail'])->name('send-email');
        Route::post('{customer}/suspend', [CustomerController::class, 'suspend'])->name('suspend');
        Route::post('{customer}/reactivate', [CustomerController::class, 'reactivate'])->name('reactivate');
        Route::post('{customer}/terminate', [CustomerController::class, 'terminate'])->name('terminate');
        Route::post('{customer}/invoices/{invoice}/refund', [CustomerController::class, 'refund'])->name('refund');
        Route::post('{customer}/credit', [CustomerController::class, 'credit'])->name('credit');
        Route::post('{customer}/pause', [CustomerController::class, 'pause'])->name('pause');
        Route::post('{customer}/resume', [CustomerController::class, 'resume'])->name('resume');
    });

    Route::middleware('can:menu.invoices')->prefix('invoices')->name('invoices.')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::get('export', [InvoiceController::class, 'export'])->name('export');
        Route::post('{invoice}/resend', [InvoiceController::class, 'resend'])->middleware('throttle:20,1')->name('resend');
        Route::get('{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('pdf');
    });

    Route::middleware('can:menu.invoices')->prefix('approvals')->name('approvals.')->group(function () {
        Route::get('/', [ApprovalController::class, 'index'])->name('index');
        Route::post('{approval}/approve', [ApprovalController::class, 'approve'])->name('approve');
        Route::post('{approval}/reject', [ApprovalController::class, 'reject'])->name('reject');
    });

    Route::middleware('can:menu.reports')->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{report}.csv', [ReportController::class, 'export'])->whereIn('report', ['funnel', 'revenue'])->name('reports.export');
    });

    Route::middleware('can:menu.quotes')->prefix('quotes')->name('quotes.')->group(function () {
        Route::get('/', [QuoteController::class, 'index'])->name('index');
        Route::post('/', [QuoteController::class, 'store'])->name('store');
        Route::post('{quote}/void', [QuoteController::class, 'void'])->name('void');
    });

    Route::middleware('can:menu.coupons')->group(function () {
        Route::get('coupons', [CouponController::class, 'index'])->name('coupons.index');
        Route::post('coupons', [CouponController::class, 'store'])->name('coupons.store');
        Route::put('coupons/{coupon}', [CouponController::class, 'update'])->name('coupons.update');
    });

    Route::middleware('can:menu.contracts')->group(function () {
        Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::get('contracts/{contract:uuid}/pdf', [ContractController::class, 'pdf'])->name('contracts.pdf');
        Route::post('contracts/{contract:uuid}/resend-renewal', [ContractController::class, 'resendRenewal'])->name('contracts.resend-renewal');
        Route::get('contract-templates/{template}', [ContractTemplateController::class, 'show'])->name('contracts.templates.show');
        Route::post('contract-templates/{template}/new-version', [ContractTemplateController::class, 'duplicate'])->name('contracts.templates.duplicate');
        Route::get('contract-templates/{template}/edit', [ContractTemplateController::class, 'edit'])->name('contracts.templates.edit');
        Route::put('contract-templates/{template}', [ContractTemplateController::class, 'update'])->name('contracts.templates.update');
        Route::post('contract-templates/{template}/publish', [ContractTemplateController::class, 'publish'])->name('contracts.templates.publish');
        Route::delete('contract-templates/{template}', [ContractTemplateController::class, 'destroy'])->name('contracts.templates.destroy');
    });

    Route::middleware('can:menu.email_templates')->prefix('email-templates')->name('email-templates.')->group(function () {
        Route::get('/', [EmailTemplateController::class, 'index'])->name('index');
        Route::get('create', [EmailTemplateController::class, 'create'])->name('create');
        Route::post('/', [EmailTemplateController::class, 'store'])->name('store');
        Route::get('{template}/edit', [EmailTemplateController::class, 'edit'])->name('edit');
        Route::put('{template}', [EmailTemplateController::class, 'update'])->name('update');
        Route::post('{template}/reset', [EmailTemplateController::class, 'reset'])->name('reset');
        Route::post('{template}/test', [EmailTemplateController::class, 'test'])->middleware('throttle:10,1')->name('test');
        Route::delete('{template}', [EmailTemplateController::class, 'destroy'])->name('destroy');
    });

    Route::middleware('can:menu.settings')->group(function () {
        Route::post('webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
        Route::put('webhooks/{endpoint}', [WebhookController::class, 'update'])->name('webhooks.update');
        Route::delete('webhooks/{endpoint}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
        Route::post('webhooks/{endpoint}/test', [WebhookController::class, 'test'])->middleware('throttle:10,1')->name('webhooks.test');
        Route::post('webhook-deliveries/{delivery}/retry', [WebhookController::class, 'retry'])->name('webhooks.retry');
        Route::post('settings/reset-data', [SettingsController::class, 'resetData'])->middleware('throttle:5,10')->name('settings.reset-data');
        Route::get('legal/{page}', [LegalPageController::class, 'edit'])->name('legal.edit');
        Route::put('legal/{page}', [LegalPageController::class, 'update'])->name('legal.update');
        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('settings/signature', [SettingsController::class, 'updateSignature'])->name('settings.signature.update');
        Route::get('settings/signature/image', [SettingsController::class, 'signature'])->name('settings.signature');
        Route::delete('settings/stripe/live', [SettingsController::class, 'clearStripeLive'])->name('settings.stripe.clear-live');
        Route::put('settings/{group}', [SettingsController::class, 'update'])
            ->whereIn('group', ['company', 'pricing', 'renewal', 'stripe', 'email', 'security', 'alerts', 'tax'])
            ->name('settings.update');
    });
});
