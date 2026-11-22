<?php

use App\Http\Controllers\Auth\ActivationController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

// Login and logout are served by Fortify (see FortifyServiceProvider).
// There is no registration: accounts are created by an admin.

Route::middleware('guest')->group(function () {
    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')->name('password.email'); // SEC-1 (RateLimits)

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset')->name('password.store'); // SEC-1

    // IT-ADMIN: the emailed activation link of a new account. GET is a signed URL
    // (403 if tampered with or past its expiry); POST uses the link once.
    Route::get('activate/{invitation}', [ActivationController::class, 'show'])
        ->whereUlid('invitation')->middleware(['signed', 'throttle:20,1'])->name('activation.show');
    Route::post('activate/{invitation}', [ActivationController::class, 'store'])
        ->whereUlid('invitation')->middleware('throttle:10,1')->name('activation.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);
});
