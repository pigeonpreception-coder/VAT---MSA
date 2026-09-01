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
use App\Http\Controllers\Business\InventoryController;
use App\Http\Controllers\Business\ProjectController;
use App\Http\Controllers\Business\QuotationController;
use App\Http\Controllers\Compliance\AuditCaseController;
use App\Http\Controllers\Compliance\CommunicationController;
use App\Http\Controllers\Compliance\DisputeController;
use App\Http\Controllers\Compliance\NotificationController;
use App\Http\Controllers\Compliance\ObligationController;
use App\Http\Controllers\Compliance\RiskController;
use App\Http\Controllers\Refund\RefundController;
use App\Http\Controllers\VatLifecycle\VatLifecycleController;
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
        // comment; the standalone VAT-rule evaluate/propose/approve routes
        // remain deferred, tracked in docs/MIGRATION_MATRIX.md.
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
        Route::post('/invoices/{id}/cancellation', [InvoiceController::class, 'cancel'])
            ->middleware('password.confirm');
        Route::get('/invoices/{id}/vat-explanation', [InvoiceController::class, 'vatExplanation']);
        Route::get('/invoices/{id}/transaction-timeline', [InvoiceController::class, 'transactionTimeline']);

        // Phase 10 (slice 1 of Accounting/commercial): business parties and
        // quotations. Kept 1:1 with the source's app/api/v1/business-parties/**
        // and app/api/v1/quotations/** shape -- see BusinessPartyController's
        // and QuotationController's own doc comments for what's deferred
        // (verifySupplier -- tracked in docs/MIGRATION_MATRIX.md).
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

        // Phase 10 (slice 4): inventory -- products, warehouses, stock
        // movements/transfers, availability/valuation. Kept 1:1 with the
        // source's app/api/v1/{products,warehouses,inventory/**}/route.ts shape.
        Route::get('/products', [InventoryController::class, 'indexProducts']);
        Route::post('/products', [InventoryController::class, 'storeProduct']);
        Route::get('/warehouses', [InventoryController::class, 'indexWarehouses']);
        Route::post('/warehouses', [InventoryController::class, 'storeWarehouse']);
        Route::get('/inventory/movements', [InventoryController::class, 'indexMovements']);
        Route::post('/inventory/movements', [InventoryController::class, 'storeMovement']);
        Route::post('/inventory/transfers', [InventoryController::class, 'storeTransfer']);
        Route::get('/inventory/availability', [InventoryController::class, 'availability']);
        Route::get('/inventory/valuation', [InventoryController::class, 'valuation']);

        // Phase 10 (slice 5, final): projects. Kept 1:1 with the source's
        // app/api/v1/projects/** shape -- this closes out every
        // business-repository.ts function except verifySupplier.
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::post('/projects/{id}/budget-approval', [ProjectController::class, 'approveBudget']);
        Route::post('/projects/{id}/costs', [ProjectController::class, 'postCost']);
        Route::get('/projects/{id}/profitability', [ProjectController::class, 'profitability']);

        // Phase 11 (slice 1): audit cases + evidence/notes, obligations,
        // disputes, and risk. Kept 1:1 with the source's app/api/v1/{
        // audit-cases,audit-evidence,obligations,disputes,risk-indicators}/**
        // and app/api/v1/taxpayers/[id]/risk-evaluation shape -- see each
        // controller's own doc comment for what's deferred (refunds,
        // communications, the standalone notification-queue commands, and
        // DOCUMENT/VAT_RETURN-sourced evidence -- tracked in
        // docs/MIGRATION_MATRIX.md's Phase 11 section).
        Route::get('/audit-cases', [AuditCaseController::class, 'index']);
        Route::post('/audit-cases', [AuditCaseController::class, 'store']);
        Route::post('/audit-cases/{id}/transition', [AuditCaseController::class, 'transition']);
        Route::post('/audit-cases/{id}/findings', [AuditCaseController::class, 'issueFinding']);
        Route::get('/audit-cases/{id}/timeline', [AuditCaseController::class, 'timeline']);
        Route::get('/audit-cases/{id}/evidence', [AuditCaseController::class, 'evidence']);
        Route::post('/audit-cases/{id}/evidence', [AuditCaseController::class, 'addEvidence']);
        Route::post('/audit-evidence/{id}/custody-events', [AuditCaseController::class, 'recordEvidenceCustodyEvent']);
        Route::get('/audit-cases/{id}/notes', [AuditCaseController::class, 'notes']);
        Route::post('/audit-cases/{id}/notes', [AuditCaseController::class, 'addNote']);

        Route::get('/obligations', [ObligationController::class, 'index']);
        Route::post('/obligations', [ObligationController::class, 'store']);
        Route::post('/obligations/{id}/satisfaction', [ObligationController::class, 'markSatisfied']);

        Route::get('/disputes', [DisputeController::class, 'index']);
        Route::post('/disputes', [DisputeController::class, 'store']);

        Route::get('/risk-indicators', [RiskController::class, 'restricted']);
        Route::post('/risk-indicators/{id}/assignment', [RiskController::class, 'assignReview']);
        Route::post('/risk-indicators/{id}/decision', [RiskController::class, 'approveAction']);
        Route::post('/taxpayers/{id}/risk-evaluation', [RiskController::class, 'evaluate']);

        // Phase 11 (slice 2): communications/conversations and the
        // standalone notification commands. Kept 1:1 with the source's
        // app/api/v1/communications/** and app/api/v1/notifications/**
        // shape -- see each controller's own doc comment for what's
        // deferred (REFUND_CLAIM-referenced notices -- tracked in
        // docs/MIGRATION_MATRIX.md's Phase 11 section).
        Route::get('/communications', [CommunicationController::class, 'inbox']);
        Route::get('/communications/{id}', [CommunicationController::class, 'show']);
        Route::post('/communications/notices', [CommunicationController::class, 'sendNotice']);
        Route::post('/communications/{id}/responses', [CommunicationController::class, 'respond']);
        Route::post('/communications/{id}/closure', [CommunicationController::class, 'close']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications', [NotificationController::class, 'queue']);
        Route::post('/notifications/{id}/cancellation', [NotificationController::class, 'cancel']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/preferences', [NotificationController::class, 'updatePreference']);

        // VAT-return-generation prerequisite (Phase 9's own deferred scope,
        // built to unblock Phase 11's refund slice -- see
        // docs/MIGRATION_MATRIX.md). Kept 1:1 with the source's
        // app/api/v1/vat-periods/**, app/api/v1/vat-returns/** and
        // app/api/v1/approval-tasks/[id]/decision/route.ts shape.
        Route::get('/vat-periods', [VatLifecycleController::class, 'periods']);
        Route::post('/vat-periods/{periodId}/adjustments', [VatLifecycleController::class, 'createAdjustment']);
        Route::post('/vat-periods/{periodId}/returns', [VatLifecycleController::class, 'generateReturn']);
        Route::get('/vat-returns/{id}', [VatLifecycleController::class, 'showReturn']);
        Route::post('/vat-returns/{versionId}/approval-requests', [VatLifecycleController::class, 'requestReturnApproval']);
        Route::post('/vat-returns/{versionId}/submissions', [VatLifecycleController::class, 'submitReturn']);
        Route::post('/approval-tasks/{taskId}/decision', [VatLifecycleController::class, 'decideApproval']);

        // Refund workflow (compliance-repository.ts's requestRefund/
        // getRefundClaimChecks/transitionRefundClaim/disputeRefund) -- kept
        // 1:1 with the source's app/api/v1/refunds/** shape. Closes out
        // Phase 11's own refund gap, now unblocked by the
        // VAT-return-generation prerequisite above.
        Route::post('/refunds', [RefundController::class, 'store']);
        Route::get('/refunds/{id}/checks', [RefundController::class, 'checks']);
        Route::post('/refunds/{id}/transition', [RefundController::class, 'transition']);
        Route::post('/refunds/{id}/disputes', [RefundController::class, 'dispute']);
    });
});
