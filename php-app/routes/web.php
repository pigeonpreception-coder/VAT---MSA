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
    });
});
