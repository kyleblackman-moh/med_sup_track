<?php

use App\Enums\Permission;
use App\Http\Controllers\RequestController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

// Vendor requests. Hidden or missing cases are 404 (ProcurementCase's global
// scope applies to route model binding).
Route::middleware(['auth', 'can:'.Permission::CaseSubmit->value])
    ->prefix('requests')
    ->name('requests.')
    ->controller(RequestController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:30,1')->name('store');
        Route::get('{procurementCase}', 'show')->name('show');
        Route::get('{procurementCase}/edit', 'edit')->name('edit');
        Route::put('{procurementCase}', 'update')->middleware('throttle:30,1')->name('update');
    });

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
