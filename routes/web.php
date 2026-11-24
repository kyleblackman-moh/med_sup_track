<?php

use App\Enums\Permission;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\FailedJobController;
use App\Http\Controllers\Admin\MfaResetController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CaseQueryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\HardcopyLookupController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\QueryInboxController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\Security\MfaController;
use App\Http\Controllers\StaffCaseActionController;
use App\Http\Controllers\StaffCaseController;
use App\Http\Controllers\SubmitRequestController;
use App\Models\ProcurementCase;
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
Route::middleware(['auth', 'case.area']) // IT-ADMIN: 404 without case work
    ->get('queries', QueryInboxController::class)
    ->middleware('throttle:60,1')
    ->name('queries.index');

// ANALYTICS: charts and tracking for the CMO's office and the PS office only
// (case.view_all). Everyone else, vendors included, gets 403.
Route::middleware(['auth', 'case.area', 'can:viewStaffCases,'.ProcurementCase::class, 'can:'.Permission::CaseViewAll->value])
    ->get('analytics', AnalyticsController::class)
    ->name('analytics');

// Vendor requests. Hidden or missing cases are 404 (ProcurementCase's global
// scope applies to route model binding).
Route::middleware(['auth', 'case.area', 'can:'.Permission::CaseSubmit->value])
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
        // 2026-11-24: the printable form (any status, own organisation; others 404 through the scope).
        Route::get('{procurementCase}/print', 'print')->middleware('throttle:30,1')->name('print');
        // TERMINAL-1 (T15V): the vendor withdraws its own request (DRAFT or SUBMITTED).
        Route::post('{procurementCase}/withdraw', 'withdraw')->middleware('throttle:10,1')->name('withdraw');
        Route::post('{procurementCase}/submit', SubmitRequestController::class)
            ->middleware('throttle:10,1')->name('submit');
        // OVERNIGHT-2 (D3): the vendor's text reply to a query from the Ministry.
        Route::post('{procurementCase}/queries/{query}/messages', [CaseQueryController::class, 'vendorReply'])
            ->middleware('throttle:20,1')->name('queries.reply');
    });

// Request documents. {document} is bound through the case (scopeBindings), so a
// document of another case is 404; hidden cases and documents are 404 as well.
Route::middleware(['auth', 'case.area', 'can:'.Permission::CaseSubmit->value])
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
Route::middleware(['auth', 'case.area', 'can:viewStaffCases,'.ProcurementCase::class])
    ->prefix('cases')
    ->name('cases.')
    ->controller(StaffCaseController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        // HARDCOPY-1: the dashboard's "Hardcopy received" box (exact reference, cases the user can see).
        Route::get('hardcopy-lookup', HardcopyLookupController::class)->middleware('throttle:60,1')->name('hardcopy-lookup');
        // WORKFLOW-2 (R3): {queryCase} also opens the page (query-only) for an officer named on a forward; anyone else is 404.
        Route::get('{queryCase}', 'show')->name('show');
        Route::get('{procurementCase}/documents/{document}/download', 'download')
            ->scopeBindings()->middleware('throttle:60,1')->name('documents.download');
        // 2026-11-24: the printable form, for anyone who can view the case (hidden and DRAFT: 404).
        Route::get('{procurementCase}/print', 'print')->middleware('throttle:30,1')->name('print');

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
            // TERMINAL-1: T15 (withdraw) and T16 (final reject). Both irreversible.
            Route::post('{procurementCase}/withdraw', 'withdraw')->name('withdraw');
            Route::post('{procurementCase}/reject-final', 'rejectFinal')->name('reject-final');
            Route::post('{procurementCase}/hardcopy', 'markHardcopy')->name('hardcopy.store');
            Route::post('{procurementCase}/hardcopy/reverse', 'reverseHardcopy')->name('hardcopy.reverse');
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

// IT-ADMIN: creating an account sits outside the console so the CMO can invite
// vendor users (users.invite_vendor). Anyone who may create neither kind of
// account gets 404 (UserController). Password confirmed in the last 15 minutes.
Route::middleware(['auth', 'admin.confirm'])
    ->prefix('admin/users')
    ->name('admin.users.')
    ->controller(UserController::class)
    ->group(function () {
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:20,1')->name('store');
    });

// IT-ADMIN: the IT Admin console. 404 without admin.access (EnsureAdminConsole);
// inside it, each action checks its own permission (403) and a missing user is 404.
Route::middleware(['auth', 'admin.console'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::prefix('users')->name('users.')->controller(UserController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{user}', 'show')->whereNumber('user')->middleware('admin.confirm')->name('show');
            Route::middleware(['admin.confirm', 'throttle:20,1'])->where(['user' => '[0-9]+'])->group(function () {
                Route::put('{user}/role', 'updateRole')->name('role');
                Route::post('{user}/disable', 'disable')->name('disable');
                Route::post('{user}/enable', 'enable')->name('enable');
                Route::post('{user}/activation', 'resendActivation')->middleware('throttle:activation')->name('activation'); // SEC-1
            });
        });

        // IT-ADMIN Part 3: the IT health page (the console home) and failed queue jobs.
        Route::middleware('admin.console:'.Permission::SystemHealthView->value)->group(function () {
            Route::get('/', SystemHealthController::class)->name('dashboard');
            Route::get('jobs', [FailedJobController::class, 'index'])->name('jobs.index');
            Route::post('jobs/{uuid}/retry', [FailedJobController::class, 'retry'])
                ->whereUuid('uuid')->middleware(['admin.confirm', 'throttle:20,1'])->name('jobs.retry');
        });

        // IT-ADMIN Part 2: the audit log (read-only; GET only, nothing else exists).
        Route::controller(AuditLogController::class)->prefix('audit')->name('audit.')->middleware('throttle:60,1')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{auditLog}', 'show')->whereNumber('auditLog')->name('show');
        });
    });

// IT-ADMIN Part 2: the same audit log for the CMO and Legal (audit.view; 404 otherwise).
Route::middleware(['auth', 'throttle:60,1'])
    ->prefix('audit')
    ->name('audit.')
    ->controller(AuditLogController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{auditLog}', 'show')->whereNumber('auditLog')->name('show');
    });

// MFA-1: enrolment, recovery codes and Security settings. EnsureMfaEnrolled lets
// security.mfa.* through for a user who has not enrolled yet.
Route::middleware(['auth'])->controller(MfaController::class)->group(function () {
    Route::get('security/mfa/setup', 'setup')->name('security.mfa.setup');
    Route::post('security/mfa/confirm', 'confirm')->middleware('throttle:10,1')->name('security.mfa.confirm');
    Route::get('security/mfa/recovery-codes', 'recoveryCodes')->name('security.mfa.recovery-codes');
    Route::get('security/mfa/recovery-codes/download', 'downloadRecoveryCodes')->name('security.mfa.recovery-codes.download');
    Route::post('security/mfa/recovery-codes/acknowledge', 'acknowledge')->name('security.mfa.recovery-codes.acknowledge');
    Route::post('security/mfa/recovery-codes/regenerate', 'regenerate')
        ->middleware(['password.confirm', 'throttle:10,1'])->name('security.mfa.regenerate');
    Route::get('settings/security', 'edit')->name('security.edit');
});

// MFA-1: Fortify's own two-factor management endpoints (enable, confirm,
// disable, QR, secret, recovery codes) are refused: users never disable or
// replace their MFA themselves; enrolment is /security/mfa. Registered after
// Fortify's routes, so these replace them. The login challenge stays Fortify's.
Route::middleware(['auth'])->group(function () {
    $refuse = fn () => abort(403, 'Multi-factor authentication is managed under Security.');
    Route::post('user/two-factor-authentication', $refuse);
    Route::delete('user/two-factor-authentication', $refuse);
    Route::post('user/confirmed-two-factor-authentication', $refuse);
    Route::get('user/two-factor-qr-code', $refuse);
    Route::get('user/two-factor-secret-key', $refuse);
    Route::get('user/two-factor-recovery-codes', $refuse);
    Route::post('user/two-factor-recovery-codes', $refuse);
});

// MFA-1: an administrator (or the CMO, for vendor users) resets a user's MFA. MfaReset authorises.
// IT-ADMIN: password confirmed in the last 15 minutes.
Route::middleware(['auth', 'admin.confirm', 'throttle:20,1'])
    ->post('admin/users/{user}/mfa-reset', MfaResetController::class)
    ->whereNumber('user')->name('admin.users.mfa-reset');

// The short-lived signed link issued by documents.download and cases.documents.download.
Route::get('documents/{document}/file', [DocumentController::class, 'file'])
    ->middleware(['auth', 'case.area', 'signed'])->name('documents.file');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
