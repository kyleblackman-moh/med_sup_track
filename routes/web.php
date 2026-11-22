<?php

use App\Enums\Permission;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CaseEndController;
use App\Http\Controllers\CaseQueryController;
use App\Http\Controllers\CoverSheetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\HardcopyLookupController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\QueryInboxController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\StaffCaseActionController;
use App\Http\Controllers\StaffCaseController;
use App\Http\Controllers\SubmitRequestController;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Queries\QueryAccess;
use App\Support\LandingPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// No public home page: guests go to sign in, signed-in users to their landing page.
Route::get('/', function (Request $request) {
    return $request->user()
        ? redirect(LandingPage::for($request->user()))
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

// NOTIF-2: the bell. The signed-in user's own notifications only; another user's id is 404.
Route::middleware(['auth'])
    ->prefix('notifications')
    ->name('notifications.')
    ->controller(NotificationController::class)
    ->group(function () {
        // NOTIF-3: "View all" is an Inertia page; the header dropdown reads latest (JSON).
        Route::get('/', 'index')->middleware('throttle:60,1')->name('index');
        Route::get('latest', 'latest')->middleware('throttle:60,1')->name('latest');
        Route::get('unread-count', 'unreadCount')->middleware('throttle:120,1')->name('unread-count');
        Route::post('read-all', 'readAll')->middleware('throttle:30,1')->name('read-all');
        Route::post('{notification}/read', 'read')->whereUuid('notification')->middleware('throttle:60,1')->name('read');
    });

// QUERIES-1: the queries waiting on the signed-in user (vendors and staff).
// Every row is found through QueryAccess; each link authorises itself again.
Route::middleware(['auth'])
    ->get('queries', QueryInboxController::class)
    ->middleware('throttle:60,1')
    ->name('queries.index');

// ANALYTICS: charts and tracking for the CMO's office and the PS office only
// (case.view_all). Everyone else, vendors included, gets 403.
Route::middleware(['auth', 'can:viewStaffCases,'.ProcurementCase::class, 'can:'.Permission::CaseViewAll->value])
    ->get('analytics', AnalyticsController::class)
    ->name('analytics');

// Vendor requests. Hidden or missing cases are 404 (ProcurementCase's global
// scope applies to route model binding).
Route::middleware(['auth', 'can:'.Permission::CaseSubmit->value])
    ->prefix('requests')
    ->name('requests.')
    ->controller(RequestController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        // V2-1b: New request posts here and lands on the new draft's form; there is
        // no create page (GET /requests/create is 404: no case has that id).
        Route::post('/', 'store')->middleware('throttle:create-request')->name('store'); // SEC-1 (RateLimits)
        Route::get('{procurementCase}', 'show')->name('show');
        Route::get('{procurementCase}/edit', 'edit')->name('edit');
        // OVERNIGHT-3 (A1): Review as its own page; Submit Request posts to requests.submit.
        Route::get('{procurementCase}/review', 'review')->name('review');
        Route::put('{procurementCase}', 'update')->middleware('throttle:30,1')->name('update');
        // V2-1: change a DRAFT's request type (one of the five).
        Route::put('{procurementCase}/type', 'changeType')->middleware('throttle:30,1')->name('type');
        // LIST-1: delete a DRAFT. A repeat of a delete that already happened is answered gracefully.
        Route::delete('{procurementCase}', 'destroy')->middleware('throttle:20,1')->name('destroy')
            ->missing(fn (Request $request) => RequestController::missingDraft($request));
        Route::get('{procurementCase}/receipt', 'receipt')->name('receipt');
        // FR-03: the printable hardcopy cover sheet (QR of the reference).
        Route::get('{procurementCase}/cover-sheet', [CoverSheetController::class, 'vendor'])->name('cover-sheet');
        // FR-09: the vendor withdraws a submitted request (T15; before intake only).
        Route::post('{procurementCase}/withdraw', [CaseEndController::class, 'vendorWithdraw'])
            ->middleware('throttle:10,1')->name('withdraw');
        Route::post('{procurementCase}/submit', SubmitRequestController::class)
            ->middleware('throttle:10,1')->name('submit');
        // OVERNIGHT-2 (D3): the vendor's text reply to a query from the Ministry.
        Route::post('{procurementCase}/queries/{query}/messages', [CaseQueryController::class, 'vendorReply'])
            ->middleware('throttle:20,1')->name('queries.reply');
    });

// Request documents. {document} is bound through the case (scopeBindings), so a
// document of another case is 404; hidden cases and documents are 404 as well.
Route::middleware(['auth', 'can:'.Permission::CaseSubmit->value])
    ->controller(DocumentController::class)
    ->group(function () {
        Route::scopeBindings()->group(function () {
            Route::post('requests/{procurementCase}/documents', 'store')
                ->middleware('throttle:uploads')->name('requests.documents.store'); // F1: one request per file; SEC-1 (RateLimits)
            Route::post('requests/{procurementCase}/documents/{document}/withdraw', 'withdraw')
                ->middleware('throttle:30,1')->name('requests.documents.withdraw');
        });

        Route::get('documents/{document}/download', 'download')
            ->middleware('throttle:60,1')->name('documents.download');
    });

Route::bind('queryCase', fn (string $value) => QueryAccess::caseForQueryWork($value, request()->user()) ?? abort(404));

// Staff case pages (slice 9a), read-only. 403 without CasePolicy::viewStaffCases;
// hidden and DRAFT cases are 404 through the global scope on route model binding.
Route::middleware(['auth', 'can:viewStaffCases,'.ProcurementCase::class])
    ->prefix('cases')
    ->name('cases.')
    ->controller(StaffCaseController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        // FR-04: the dashboard's reference lookup. Before {queryCase}, which would take "lookup" as an id.
        Route::get('lookup', HardcopyLookupController::class)->middleware('throttle:60,1')->name('lookup');
        // WORKFLOW-2 (R3): {queryCase} also opens the page (query-only) for an officer named on a forward; anyone else is 404.
        Route::get('{queryCase}', 'show')->name('show');
        Route::get('{procurementCase}/documents/{document}/download', 'download')
            ->scopeBindings()->middleware('throttle:60,1')->name('documents.download');
        Route::get('{procurementCase}/cover-sheet', [CoverSheetController::class, 'staff'])->name('cover-sheet');

        // Slice 9b actions. 20 a minute per user.
        Route::controller(StaffCaseActionController::class)->middleware('throttle:20,1')->group(function () {
            Route::post('{procurementCase}/intake', 'intake')->name('intake');
            Route::post('{procurementCase}/forward-for-review', 'forwardForReview')->name('forward');
            Route::post('{procurementCase}/technical-review', 'technicalReview')->name('technical');
            Route::post('{procurementCase}/funding', 'funding')->name('funding');
            Route::post('{procurementCase}/ready-for-file', 'readyForFile')->name('ready');
            Route::post('{procurementCase}/prepare-file', 'prepareFile')->name('prepare');
            Route::post('{procurementCase}/send-to-legal', 'sendToLegal')->name('legal.send');
            Route::post('{procurementCase}/legal-decision', 'legalDecision')->name('legal.decide');
            Route::post('{procurementCase}/ps-decision', 'psDecision')->name('ps.decide');
            Route::post('{procurementCase}/route-onward', 'routeOnward')->name('route.onward');
            Route::post('{procurementCase}/issue-letter', 'issueLetter')->name('letter.issue');
            Route::post('{procurementCase}/close', 'closeCase')->name('close');
            Route::post('{procurementCase}/hardcopy', 'markHardcopy')->name('hardcopy.store');
            Route::post('{procurementCase}/hardcopy/reverse', 'reverseHardcopy')->name('hardcopy.reverse');
        });

        // FR-09: withdraw (T15) and final rejection on the PS's instruction (T16). Irreversible.
        Route::controller(CaseEndController::class)->middleware('throttle:10,1')->group(function () {
            Route::post('{procurementCase}/withdraw', 'withdraw')->name('withdraw');
            Route::post('{procurementCase}/reject', 'reject')->name('reject');
        });

        // OVERNIGHT-2 (D2): query loops. An unknown or invisible query or message is 404.
        // WORKFLOW-2: {queryCase} also reaches an officer the CMO named on a forward
        // (QueryAccess::caseForQueryWork); anyone else still gets 404, as with {procurementCase}.
        Route::controller(CaseQueryController::class)->middleware('throttle:20,1')->group(function () {
            Route::post('{procurementCase}/queries', 'store')->name('queries.store');
            Route::post('{queryCase}/queries/{query}/messages', 'reply')->name('queries.reply');
            Route::post('{queryCase}/queries/{query}/messages/{message}/forward', 'forward')->name('queries.forward');
            Route::post('{queryCase}/queries/{query}/close', 'close')->name('queries.close');
        });
    });

// ADMIN-1: Admin > Users. 403 without users.manage (UserPolicy); a missing user is 404.
Route::middleware(['auth', 'can:viewAny,'.User::class])
    ->prefix('admin/users')
    ->name('admin.users.')
    ->controller(UserController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:20,1')->name('store');
        Route::get('{user}', 'show')->whereNumber('user')->name('show');
        Route::middleware('throttle:20,1')->where(['user' => '[0-9]+'])->group(function () {
            Route::put('{user}/role', 'updateRole')->name('role');
            Route::post('{user}/disable', 'disable')->name('disable');
            Route::post('{user}/enable', 'enable')->name('enable');
            Route::post('{user}/activation', 'resendActivation')->middleware('throttle:activation')->name('activation'); // SEC-1
        });
    });

// The short-lived signed link issued by documents.download and cases.documents.download.
Route::get('documents/{document}/file', [DocumentController::class, 'file'])
    ->middleware(['auth', 'signed'])->name('documents.file');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
