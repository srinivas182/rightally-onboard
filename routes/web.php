<?php

use App\Http\Controllers\BrevoWebhookController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\Onboarding\AccountController;
use App\Http\Controllers\Onboarding\CouponCheckController;
use App\Http\Controllers\Onboarding\OnboardingController;
use App\Http\Controllers\Onboarding\RenewalController;
use App\Http\Controllers\StripeWebhookController;
use App\Services\Admin\SystemHealth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Client onboarding (public)
|--------------------------------------------------------------------------
| 1 details -> 2 review and sign -> 3 payment schedule -> 4 payment -> 5 done
| Pages after step 1 are only reachable from the browser that started the
| onboarding, or through a signed link (see OnboardingAccess).
*/

Route::get('/', [OnboardingController::class, 'start'])->name('home');
Route::get('/{slug}', [LegalPageController::class, 'show'])->whereIn('slug', ['privacy', 'terms'])->name('legal');
Route::post('/start', [OnboardingController::class, 'store'])->middleware('throttle:onboarding')->name('onboarding.store');
Route::post('/coupon/check', CouponCheckController::class)->middleware('throttle:coupon-check')->name('onboarding.coupon');

Route::prefix('onboard/{customer}')->name('onboarding.')->middleware('onboarding.access')->group(function () {
    Route::get('details', [OnboardingController::class, 'editDetails'])->name('details');
    Route::put('details', [OnboardingController::class, 'updateDetails'])->middleware('throttle:onboarding')->name('details.update');
    Route::get('agreement', [OnboardingController::class, 'agreement'])->name('agreement');
    Route::post('agreement/sign', [OnboardingController::class, 'sign'])->middleware('throttle:onboarding')->name('sign');
    Route::post('agreement/delegate', [OnboardingController::class, 'delegate'])->middleware('throttle:onboarding')->name('delegate');
    Route::post('email', [OnboardingController::class, 'updateEmail'])->middleware('throttle:onboarding')->name('email');
    Route::get('agreement/pdf', [OnboardingController::class, 'pdf'])->name('pdf');
    Route::get('schedule', [OnboardingController::class, 'schedule'])->name('schedule');
    Route::get('payment', [OnboardingController::class, 'payment'])->middleware('throttle:onboarding')->name('payment');
    Route::get('payment/return', [OnboardingController::class, 'paymentReturn'])->name('payment.return');
    Route::get('done', [OnboardingController::class, 'done'])->name('done');
});

// Client account (no password): request a link, then open it from the email.
Route::get('/account', [AccountController::class, 'request'])->name('account.request');
Route::post('/account', [AccountController::class, 'sendLink'])->middleware('throttle:5,10')->name('account.send-link');
Route::prefix('account/{customer}')->name('account.')->middleware('onboarding.access')->group(function () {
    Route::get('/', [AccountController::class, 'show'])->name('show');
    Route::get('invoices/{invoice}/pdf', [AccountController::class, 'invoicePdf'])->name('invoice-pdf');
    Route::get('agreements/{contract:uuid}/pdf', [AccountController::class, 'agreementPdf'])->name('agreement-pdf');
    Route::get('payment-method', [AccountController::class, 'paymentMethod'])->middleware('throttle:10,1')->name('payment-method');
    Route::get('payment-method/return', [AccountController::class, 'paymentMethodReturn'])->name('payment-method.return');
});

// Renewal agreements, opened from the emailed (signed) link.
Route::middleware('signed')->prefix('renew/{contract:uuid}')->name('renewal.')->group(function () {
    Route::get('/', [RenewalController::class, 'show'])->name('show');
    Route::post('sign', [RenewalController::class, 'sign'])->middleware('throttle:onboarding')->name('sign');
    Route::get('pdf', [RenewalController::class, 'pdf'])->name('pdf');
});

// Stripe webhooks (no session, no CSRF; verified by signature).
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

// Email delivery events from Brevo (secret token in the URL, see Settings > Email).
Route::post('/brevo/webhook/{token}', BrevoWebhookController::class)->middleware('throttle:600,1')->name('brevo.webhook');

// Health check for uptime monitoring: 200 when everything billing needs is working, 503 otherwise. No secrets.
Route::get('/health', function (SystemHealth $health) {
    $checks = collect($health->checks())->map(fn ($c) => $c['ok']);

    return response()->json(['ok' => $checks->every(fn ($ok) => $ok), 'checks' => $checks], $checks->every(fn ($ok) => $ok) ? 200 : 503);
})->middleware('throttle:30,1')->name('health');
