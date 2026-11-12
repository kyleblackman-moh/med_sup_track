<?php

use App\Enums\Permission;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\SubmitRequestController;
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
        Route::get('{procurementCase}/receipt', 'receipt')->name('receipt');
        Route::post('{procurementCase}/submit', SubmitRequestController::class)
            ->middleware('throttle:10,1')->name('submit');
    });

// Request documents. {document} is bound through the case (scopeBindings), so a
// document of another case is 404; hidden cases and documents are 404 as well.
Route::middleware(['auth', 'can:'.Permission::CaseSubmit->value])
    ->controller(DocumentController::class)
    ->group(function () {
        Route::scopeBindings()->group(function () {
            Route::post('requests/{procurementCase}/documents', 'store')
                ->middleware('throttle:20,1')->name('requests.documents.store');
            Route::post('requests/{procurementCase}/documents/{document}/withdraw', 'withdraw')
                ->middleware('throttle:30,1')->name('requests.documents.withdraw');
        });

        Route::get('documents/{document}/download', 'download')
            ->middleware('throttle:60,1')->name('documents.download');
    });

// The short-lived signed link issued by documents.download.
Route::get('documents/{document}/file', [DocumentController::class, 'file'])
    ->middleware(['auth', 'signed'])->name('documents.file');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
