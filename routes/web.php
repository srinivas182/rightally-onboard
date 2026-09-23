<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
| The client onboarding flow (details, agreement, schedule, payment) is
| built in Sprint 2. Until then "/" shows a holding page.
*/

Route::view('/', 'onboarding.coming-soon')->name('home');
