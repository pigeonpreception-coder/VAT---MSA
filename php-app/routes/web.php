<?php

use App\Http\Controllers\Auth\ConfirmPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Identity\BranchController;
use App\Http\Controllers\Identity\MembershipController;
use App\Http\Controllers\Identity\OrganisationController;
use App\Http\Controllers\Identity\RegistrationApplicationController;
use App\Http\Controllers\Identity\TaxpayerController;
use App\Http\Controllers\Invoice\InvoiceController;
use App\Http\Controllers\Business\AccountingController;
use App\Http\Controllers\Business\BusinessPartyController;
use App\Http\Controllers\Business\ExpenseController;
use App\Http\Controllers\Business\QuotationController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

// Phase 6: local Laravel session authentication, replacing the source's
// platform-header trust entirely (see LoginRequest's own doc comment).
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/confirm-password', [ConfirmPasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmPasswordController::class, 'store']);

    // Phase 8: organisations, taxpayers, registration applications, branches,
    // memberships -- URL shapes kept 1:1 with the source's app/api/v1/**
    // routes for traceability, even though this is Blade/session-driven
    // rather than a separate token-authenticated API surface.
    Route::prefix('api/v1')->group(function () {
        Route::get('/registration-applications', [RegistrationApplicationController::class, 'index']);
        Route::post('/registration-applications', [RegistrationApplicationController::class, 'store']);
        Route::post('/registration-applications/{id}/decision', [RegistrationApplicationController::class, 'decision'])
            ->middleware('password.confirm');

        Route::get('/organisations', [OrganisationController::class, 'index']);
        Route::get('/organisations/{id}', [OrganisationController::class, 'show']);

        Route::get('/organisations/{organisation}/branches', [BranchController::class, 'index']);
        Route::post('/organisations/{organisation}/branches', [BranchController::class, 'store']);
        Route::patch('/organisations/{organisation}/branches/{branch}', [BranchController::class, 'update']);

        Route::post('/organisations/{organisation}/memberships', [MembershipController::class, 'store'])
            ->middleware('password.confirm');

        Route::post('/taxpayers/{id}/suspension', [TaxpayerController::class, 'suspend'])
            ->middleware('password.confirm');

        // Phase 9: invoice certification and VAT. Kept 1:1 with the source's
        // app/api/v1/invoices/** shape -- see InvoiceController's own doc
        // comment for what is and isn't ported yet (cancellation, transaction
        // timeline, VAT explanation and the standalone VAT-rule evaluate route
        // are deferred, tracked in docs/MIGRATION_MATRIX.md).
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::get('/invoices/{id}', [InvoiceController::class, 'show']);

        // Phase 10 (slice 1 of Accounting/commercial): business parties and
        // quotations. Kept 1:1 with the source's app/api/v1/business-parties/**
        // and app/api/v1/quotations/** shape -- see BusinessPartyController's
        // and QuotationController's own doc comments for what's deferred
        // (verifySupplier, expenses, inventory/products/warehouses, projects
        // -- tracked in docs/MIGRATION_MATRIX.md).
        Route::get('/business-parties', [BusinessPartyController::class, 'index']);
        Route::post('/business-parties', [BusinessPartyController::class, 'store']);
        Route::patch('/business-parties/{id}', [BusinessPartyController::class, 'update']);
        Route::post('/business-parties/{id}/deactivation', [BusinessPartyController::class, 'deactivate']);

        Route::get('/quotations', [QuotationController::class, 'index']);
        Route::post('/quotations', [QuotationController::class, 'store']);
        Route::patch('/quotations/{id}', [QuotationController::class, 'update']);
        Route::post('/quotations/{id}/sending', [QuotationController::class, 'send']);
        Route::post('/quotations/{id}/accept', [QuotationController::class, 'accept']);
        Route::post('/quotations/{id}/rejection', [QuotationController::class, 'reject']);
        Route::post('/quotations/{id}/expiration', [QuotationController::class, 'expire']);
        Route::post('/quotations/{id}/convert', [QuotationController::class, 'convert']);

        // Phase 10 (slice 2): accounting -- journals, chart of accounts,
        // period close, trial balance, financial statements. Kept 1:1 with
        // the source's app/api/v1/accounting/** shape -- see
        // AccountingController's own doc comment for what's deferred.
        Route::get('/accounting/accounts', [AccountingController::class, 'indexAccounts']);
        Route::post('/accounting/accounts', [AccountingController::class, 'storeAccount']);
        Route::get('/accounting/journals', [AccountingController::class, 'indexJournals']);
        Route::post('/accounting/journals', [AccountingController::class, 'storeJournal']);
        Route::post('/accounting/journals/{id}/reversal', [AccountingController::class, 'reverseJournal']);
        Route::post('/accounting/periods/closure', [AccountingController::class, 'closePeriod']);
        Route::get('/accounting/trial-balance', [AccountingController::class, 'trialBalance']);
        Route::get('/accounting/statements', [AccountingController::class, 'statements']);

        // Phase 10 (slice 3): expenses. Kept 1:1 with the source's
        // app/api/v1/expenses/** shape -- see ExpenseController's own doc
        // comment for what's deferred.
        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store']);
        Route::get('/expenses/categories', [ExpenseController::class, 'indexCategories']);
        Route::post('/expenses/categories', [ExpenseController::class, 'storeCategory']);
        Route::get('/expenses/report', [ExpenseController::class, 'report']);
        Route::post('/expenses/{id}/submission', [ExpenseController::class, 'submit']);
        Route::post('/expenses/{id}/approval', [ExpenseController::class, 'approve']);
        Route::post('/expenses/{id}/rejection', [ExpenseController::class, 'reject']);
    });
});
