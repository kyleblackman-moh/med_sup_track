<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    // Light only (NEXT part 4): there is no appearance setting. Old links land on the profile page.
    Route::redirect('settings/appearance', 'settings/profile')->name('appearance');
});

// MFA (spec 8.2): Settings > Security and the two-factor setup page. Behind
// password.confirm because Fortify's two-factor endpoints require it; a fresh
// sign-in counts as confirmed (LoginResolvedUser). The writes are Fortify's routes.
Route::middleware(['auth', 'password.confirm'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'show'])->name('security.edit');
    Route::get('settings/two-factor', [SecurityController::class, 'setup'])->name('two-factor.setup');
});
