<?php

use App\Http\Controllers\Onboarding\CouponCheckController;
use App\Http\Controllers\Onboarding\OnboardingController;
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
Route::post('/start', [OnboardingController::class, 'store'])->middleware('throttle:onboarding')->name('onboarding.store');
Route::post('/coupon/check', CouponCheckController::class)->middleware('throttle:coupon-check')->name('onboarding.coupon');

Route::prefix('onboard/{customer}')->name('onboarding.')->middleware('onboarding.access')->group(function () {
    Route::get('details', [OnboardingController::class, 'editDetails'])->name('details');
    Route::put('details', [OnboardingController::class, 'updateDetails'])->middleware('throttle:onboarding')->name('details.update');
    Route::get('agreement', [OnboardingController::class, 'agreement'])->name('agreement');
    Route::post('agreement/sign', [OnboardingController::class, 'sign'])->middleware('throttle:onboarding')->name('sign');
    Route::get('agreement/pdf', [OnboardingController::class, 'pdf'])->name('pdf');
    Route::get('schedule', [OnboardingController::class, 'schedule'])->name('schedule');
    Route::get('payment', [OnboardingController::class, 'payment'])->name('payment');
});
