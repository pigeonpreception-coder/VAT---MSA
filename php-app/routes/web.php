<?php

use App\Http\Controllers\AccessGovernance\AccessGovernanceController;
use App\Http\Controllers\Administration\AdministrationController;
use App\Http\Controllers\Auth\ConfirmPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Identity\BranchController;
use App\Http\Controllers\Identity\IdentityFoundationController;
use App\Http\Controllers\Identity\MembershipController;
use App\Http\Controllers\Identity\OrganisationController;
use App\Http\Controllers\Identity\RegistrationApplicationController;
use App\Http\Controllers\Identity\TaxpayerController;
use App\Http\Controllers\Invoice\InvoiceController;
use App\Http\Controllers\Invoice\InvoiceViewController;
use App\Http\Controllers\Business\AccountingController;
use App\Http\Controllers\Business\BusinessPartyController;
use App\Http\Controllers\Business\ExpenseController;
use App\Http\Controllers\Business\InventoryController;
use App\Http\Controllers\Business\ProjectController;
use App\Http\Controllers\Business\QuotationController;
use App\Http\Controllers\Compliance\AuditCaseController;
use App\Http\Controllers\Compliance\ComplianceSnapshotController;
use App\Http\Controllers\Compliance\CommunicationController;
use App\Http\Controllers\Compliance\DisputeController;
use App\Http\Controllers\Compliance\NotificationController;
use App\Http\Controllers\Compliance\ObligationController;
use App\Http\Controllers\Compliance\RiskController;
use App\Http\Controllers\Document\DocumentController;
use App\Http\Controllers\Refund\RefundController;
use App\Http\Controllers\VatLifecycle\VatLifecycleController;
use App\Http\Controllers\Licensing\LicensingController;
use App\Http\Controllers\Navigation\NavigationController;
use App\Http\Controllers\OrganisationAdmin\OrganisationAdminController;
use App\Http\Controllers\Platform\DataProductController;
use App\Http\Controllers\Platform\OfflineSyncController;
use App\Http\Controllers\Platform\PlatformConfigController;
use App\Http\Controllers\Platform\PlatformSnapshotController;
use App\Http\Controllers\Platform\ReportController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\VatRule\VatRuleController;
use App\Http\Controllers\Workflow\WorkflowController;
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

    // Real Blade UI, alongside the JSON API surface below -- see
    // InvoiceViewController's own doc comment. Registered before the
    // /api/v1/invoices/** JSON routes' own /invoices path so there is no
    // ambiguity: this is a genuinely different URL (no api/v1 prefix).
    Route::get('/invoices', [InvoiceViewController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{id}', [InvoiceViewController::class, 'show'])->name('invoices.show');

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

        // getIdentityFoundationSnapshot -- Module 1's own dashboard
        // aggregate (organisations + registrations + identity providers +
        // scope-wide access counts + the ITAS integration status). The
        // source consumes this directly from server-component pages
        // (app/organisations/page.tsx, app/portal/{namra,namra-admin}/
        // page.tsx), not through an app/api/v1/** route file -- exposed as
        // one here anyway, matching every other snapshot in this migration.
        // Closes out Phase 8's own last deferred piece.
        Route::get('/identity', [IdentityFoundationController::class, 'show']);

        Route::get('/organisations', [OrganisationController::class, 'index']);
        // Registered before the /organisations/{id} wildcard below --
        // Laravel matches routes in registration order, and each of these
        // literal segments would otherwise be swallowed by {id} and 404
        // inside OrganisationController::show (found the hard way, for
        // "capabilities", via OrganisationAdminTest's own listing
        // assertion -- the same fix applied proactively here for
        // employees/roles/administrators too).
        Route::get('/organisations/capabilities', [OrganisationAdminController::class, 'capabilities']);
        Route::get('/organisations/employees', [OrganisationAdminController::class, 'listEmployees']);
        Route::get('/organisations/roles', [OrganisationAdminController::class, 'listRoles']);
        Route::get('/organisations/administrators', [OrganisationAdminController::class, 'listAdministrators']);
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

        // The standalone VAT-rule evaluate/propose/approve routes -- the
        // last narrow gap Phase 9 (invoices and VAT) deferred. Kept 1:1
        // with the source's app/api/v1/vat-rules/** shape; propose/approve
        // are step-up gated exactly like invoice cancellation above.
        Route::get('/vat-rules', [VatRuleController::class, 'index']);
        Route::post('/vat-rules', [VatRuleController::class, 'store'])
            ->middleware('password.confirm');
        Route::get('/vat-rules/evaluate', [VatRuleController::class, 'evaluate']);
        Route::post('/vat-rules/{id}/approval', [VatRuleController::class, 'approve'])
            ->middleware('password.confirm');

        // Phase 10 (slice 1 of Accounting/commercial): business parties and
        // quotations. Kept 1:1 with the source's app/api/v1/business-parties/**
        // and app/api/v1/quotations/** shape.
        Route::get('/business-parties', [BusinessPartyController::class, 'index']);
        Route::post('/business-parties', [BusinessPartyController::class, 'store']);
        Route::patch('/business-parties/{id}', [BusinessPartyController::class, 'update']);
        Route::post('/business-parties/{id}/deactivation', [BusinessPartyController::class, 'deactivate']);
        Route::get('/business-parties/{id}/verification', [BusinessPartyController::class, 'verificationHistory']);
        Route::post('/business-parties/{id}/verification', [BusinessPartyController::class, 'verify']);

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

        // Module 22's Documents & Records slice: the Upload -> Quarantine ->
        // ScanDecision chain pulled forward in Phase 11, plus Phase 13's own
        // supersede/versions/retention-hold/download -- see
        // App\Services\Document\DocumentService's doc comment for the rest
        // of Module 22 that remains deliberately out of this slice.
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::post('/documents/{id}/scan-result', [DocumentController::class, 'scanResult']);
        Route::post('/documents/{id}/supersession', [DocumentController::class, 'supersede']);
        Route::get('/documents/{id}/versions', [DocumentController::class, 'versions']);
        Route::post('/documents/{id}/retention-hold', [DocumentController::class, 'retentionHold']);
        Route::get('/documents/{id}/download', [DocumentController::class, 'download']);

        // Phase 11's own fixed-list dashboard aggregate, closing out the
        // phase's one remaining gap.
        Route::get('/compliance', [ComplianceSnapshotController::class, 'show']);

        // Module 22's platform/developer-portal snapshot reads (Phase 13) --
        // see App\Services\Platform\PlatformSnapshotService's own doc
        // comment for what's still out of this slice.
        Route::get('/platform', [PlatformSnapshotController::class, 'show']);
        Route::get('/platform/document-custody', [PlatformSnapshotController::class, 'documentCustody']);
        Route::get('/platform/developer-portal', [PlatformSnapshotController::class, 'developerPortal']);

        // Module 22's offline-invoicing sync-batch intake (Phase 13, third
        // slice) -- kept 1:1 with the source's
        // app/api/v1/offline/batches/route.ts shape. See
        // App\Services\Platform\OfflineSyncService's own doc comment for
        // what else in platform-repository.ts remains out of this slice.
        Route::post('/offline/batches', [OfflineSyncController::class, 'store']);

        // Module 7 Phases A-C's report-run/report-export commands (Phase 13,
        // fourth slice) -- kept 1:1 with the source's own
        // app/api/v1/reports/** route shapes. See
        // App\Services\Platform\ReportExportService's own doc comment for
        // what else in platform-repository.ts remains out of this slice.
        Route::post('/reports/{code}/runs', [ReportController::class, 'run']);
        Route::post('/reports/runs/{id}/publication', [ReportController::class, 'publish']);
        Route::post('/reports/runs/{id}/exports', [ReportController::class, 'requestExport']);
        Route::get('/reports/exports/{id}', [ReportController::class, 'showExport']);
        Route::post('/reports/exports/{id}/approval', [ReportController::class, 'approveExport']);
        Route::post('/reports/exports/{id}/cancellation', [ReportController::class, 'cancelExport']);
        Route::get('/reports/exports/{id}/download', [ReportController::class, 'downloadExport']);

        // Module 7 Phase D's data-products/analytics reads and commands
        // (Phase 13, fifth slice) -- kept 1:1 with the source's own
        // app/api/v1/analytics/** route shapes. See
        // App\Services\Platform\DataProductService's own doc comment for
        // what else in platform-repository.ts remains out of this slice.
        Route::get('/analytics/data-products', [DataProductController::class, 'index']);
        Route::post('/analytics/data-products/{id}/model-runs', [DataProductController::class, 'runModel']);
        Route::post('/analytics/data-products/{id}/publications', [DataProductController::class, 'publish']);
        Route::get('/analytics/metrics', [DataProductController::class, 'metrics']);
        Route::get('/analytics/anomalies', [DataProductController::class, 'anomalies']);

        // Module 8 Phase A's platform config/change-management commands
        // (Phase 13, sixth and final slice) -- closes out
        // platform-repository.ts entirely. Kept 1:1 with the source's own
        // app/api/v1/platform/** route shapes. provisionStaff is
        // unconditionally step-up gated (password.confirm), unlike the
        // report-export commands' data-conditional step-up.
        Route::get('/platform/config', [PlatformConfigController::class, 'config']);
        Route::get('/platform/change-requests', [PlatformConfigController::class, 'changeRequests']);
        Route::post('/platform/change-requests', [PlatformConfigController::class, 'requestChange']);
        Route::post('/platform/change-requests/{id}/decision', [PlatformConfigController::class, 'decideChange']);
        Route::post('/platform/staff', [PlatformConfigController::class, 'provisionStaff'])
            ->middleware('password.confirm');

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

        // Phase 12 (portals/licensing/governance), slice 1: Licensing &
        // Entitlements (lib/data/control-plane-repository.ts's
        // getEntitlementsSnapshot/getUsageSnapshot/changeLicenseState/
        // upgradeLicense). Kept 1:1 with the source's
        // app/api/v1/licensing/** shape; state/upgrade are step-up gated.
        Route::get('/licensing/entitlements', [LicensingController::class, 'entitlements']);
        Route::get('/licensing/usage', [LicensingController::class, 'usage']);
        Route::get('/licensing/license', [LicensingController::class, 'license']);
        Route::post('/licensing/state', [LicensingController::class, 'state'])
            ->middleware('password.confirm');
        Route::post('/licensing/upgrade', [LicensingController::class, 'upgrade'])
            ->middleware('password.confirm');

        // Phase 12 slice 2: organisation administration/employees (also
        // closing out "the rest of Phase 8" -- employees, organisation-
        // defined custom roles) plus openQuarterlyAccessReview, pulled
        // forward from Access governance since assertEntitledOperation's
        // own ADMIN_WRITE gate hard-requires it. Every GET list route in
        // the source bundles its data inside getAdministrationSnapshot
        // (the dashboard aggregate, deferred) except listCapabilityGrants,
        // the one standalone read ported here. The workflow engine and the
        // rest of Access governance (certifyQuarterlyAccess and beyond)
        // remain deferred -- see docs/MIGRATION_MATRIX.md.
        Route::post('/organisations/employees', [OrganisationAdminController::class, 'storeEmployee'])
            ->middleware('password.confirm');
        Route::post('/organisations/employees/{id}/activation', [OrganisationAdminController::class, 'activateEmployee'])
            ->middleware('password.confirm');
        Route::post('/organisations/employees/{id}/termination', [OrganisationAdminController::class, 'terminateEmployee'])
            ->middleware('password.confirm');
        Route::post('/organisations/administrators', [OrganisationAdminController::class, 'storeAdministrator'])
            ->middleware('password.confirm');
        Route::post('/organisations/roles', [OrganisationAdminController::class, 'storeRole'])
            ->middleware('password.confirm');
        // GET /organisations/capabilities is registered earlier, above the
        // /organisations/{id} wildcard -- see that route's comment.
        Route::post('/organisations/capabilities', [OrganisationAdminController::class, 'storeCapability'])
            ->middleware('password.confirm');
        Route::get('/access-reviews', [AccessGovernanceController::class, 'listAccessReviews']);
        Route::post('/access-reviews', [OrganisationAdminController::class, 'storeAccessReview'])
            ->middleware('password.confirm');

        // Phase 12 slice 3: portal navigation (getEffectiveNavigation/
        // getNavigationChildren/getNavigationItemActions/
        // saveNavigationPreference). Closes out control-plane-repository.ts's
        // last self-contained sub-domain -- only the workflow engine and
        // the rest of Access governance remain. SavePreference is
        // deliberately not step-up gated, matching the source (a UI
        // preference isn't a privileged action).
        Route::get('/navigation/workspace', [NavigationController::class, 'workspace']);
        Route::get('/navigation/children', [NavigationController::class, 'children']);
        Route::get('/navigation/actions', [NavigationController::class, 'actions']);
        Route::post('/navigation/preferences', [NavigationController::class, 'storePreference']);
        // searchWorkspace -- a genuinely separate Workspace & Navigation
        // route (/api/v1/search in the source), not part of any other
        // Phase 12 slice. See docs/MIGRATION_MATRIX.md.
        Route::get('/search', [NavigationController::class, 'search']);

        // Phase 12 slice 4: the rest of Access governance (requestRoleAccess/
        // decideAccessRequest/certifyQuarterlyAccess/revokeAccessGrant/
        // offboardUser). openQuarterlyAccessReview itself was already ported
        // in slice 2 -- see the /access-reviews route above. Only the
        // initial access *request* is not step-up gated (matching the
        // source -- it needs access-governance:read, not :manage, and no
        // requireStepUp call); every decide/certify/revoke/offboard
        // command is.
        Route::get('/access-requests', [AccessGovernanceController::class, 'listAccessRequests']);
        Route::post('/access-requests', [AccessGovernanceController::class, 'storeAccessRequest']);
        Route::post('/access-requests/{id}/decision', [AccessGovernanceController::class, 'decideAccessRequest'])
            ->middleware('password.confirm');
        Route::post('/access-reviews/{id}/certifications', [AccessGovernanceController::class, 'storeCertification'])
            ->middleware('password.confirm');
        Route::post('/access-grants/revocation', [AccessGovernanceController::class, 'storeRevocation'])
            ->middleware('password.confirm');
        Route::post('/organisations/offboarding', [AccessGovernanceController::class, 'storeOffboarding'])
            ->middleware('password.confirm');

        // Phase 12 slice 5: the workflow engine (Module 8 Phase C --
        // createWorkflowDraft/publishWorkflowVersion/assignWorkflow/
        // decideWorkflowTask/testWorkflowVersion/createDelegation/
        // listDelegations/revokeDelegation). GET /workflows/delegations
        // is a genuinely standalone read. Test is not step-up gated (a
        // dry-run has no side effects); every other write command is.
        Route::get('/workflows', [WorkflowController::class, 'listWorkflows']);
        Route::post('/workflows', [WorkflowController::class, 'storeWorkflow'])
            ->middleware('password.confirm');
        Route::post('/workflows/versions/{id}/publication', [WorkflowController::class, 'publishVersion'])
            ->middleware('password.confirm');
        Route::post('/workflows/versions/{id}/test', [WorkflowController::class, 'testVersion']);
        Route::post('/workflows/instances', [WorkflowController::class, 'storeInstance'])
            ->middleware('password.confirm');
        Route::post('/workflow-tasks/{id}/decision', [WorkflowController::class, 'decideTask'])
            ->middleware('password.confirm');
        Route::get('/workflows/delegations', [WorkflowController::class, 'delegations']);
        Route::post('/workflows/delegations', [WorkflowController::class, 'storeDelegation'])
            ->middleware('password.confirm');
        Route::post('/workflows/delegations/{id}/revocation', [WorkflowController::class, 'revokeDelegation'])
            ->middleware('password.confirm');

        // getAdministrationSnapshot's own full, unsliced route -- the
        // fixed-list dashboard aggregate every other GET-list route
        // across all five of Phase 12's sub-domain slices above bundles
        // into instead of a dedicated query of its own. Closes out
        // control-plane-repository.ts entirely -- see
        // docs/MIGRATION_MATRIX.md.
        Route::get('/administration', [AdministrationController::class, 'show']);

        // lib/portals.ts's getAvailablePortals -- a genuinely separate
        // file from control-plane-repository.ts, found and closed out
        // alongside it. See PortalDefinitions' own doc comment.
        Route::get('/portals', [PortalController::class, 'index']);
    });
});
