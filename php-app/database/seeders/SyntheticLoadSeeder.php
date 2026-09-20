<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Red-team punch list #12 (docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_
 * 2026-09-15.md): "Real query/N+1 performance at production-
 * representative data volumes was never tested" -- the
 * 2026-09-14-CONCURRENT-USER-SIMULATION report's own honest finding was
 * that dev seed data (0 invoices, 5 fixed assets, 12 users at the time)
 * was too small to make query-plan performance or N+1 detection
 * meaningful, and said this needed "either a production-scale seed or a
 * staging environment closer to production sizing" to close.
 *
 * This is that seed. Not wired into DatabaseSeeder's default run -- it's
 * deliberately large (tens of thousands of rows) and meant to be run
 * on demand (`php artisan db:seed --class=SyntheticLoadSeeder`) against
 * a disposable dev database when actually measuring query counts/timing,
 * not part of every fresh install. Bulk `DB::table()->insert()` in
 * chunks throughout (not Eloquent model creation, not the real service/
 * validator layer) -- the goal is raw row volume to make N+1 patterns
 * visible in query counts, not exercising business rules; every write
 * here is a direct, minimal, schema-valid row.
 *
 * Row counts and proportions are chosen to resemble a real multi-tenant
 * VAT platform at moderate-to-heavy usage, not an arbitrary round
 * number: ~20 active taxpayers (a small country's worth of registered
 * businesses actually captured in one seed), each with several years'
 * worth of invoices/expenses/documents, a handful of national NAMRA
 * staff, and case/evidence volumes consistent with an audit caseload
 * that has been running for a while, not day one.
 *
 * Extended 2026-09-20 (see docs/LAUNCH_READINESS_BACKLOG.md item #10's
 * own note) with a second block, `seedLedgerAndProjectVolume()`: the
 * 2026-09-15 run above never touched quotations, projects, business
 * parties at volume, or chart-of-accounts/journal postings, because none
 * of the pages that read them at list-view volume existed yet. This
 * session added several that explicitly claim to batch those same reads
 * into a fixed number of queries regardless of row count
 * (`SupplierLedgerService`/`CustomerLedgerService`, `BudgetsView
 * Controller`, `CashFlowViewController`, `ProjectManagementView
 * Controller`, `QuotationService::crossReference()`) -- a claim never
 * run against real volume until now. Concentrated on one single
 * organisation (index 0) rather than spread across all 20, since every
 * one of those pages is itself organisation-scoped: what makes an N+1
 * visible is row count *on one page*, not total rows in the database.
 *
 * Scaled 15x on the row-volume constants (2026-09-20, same day) at the
 * user's own request for a heavier stress pass, with one deliberate
 * exception: TAXPAYER_COUNT/USERS_PER_TAXPAYER/NAMRA_STAFF_COUNT are
 * left unchanged. Those control how many *organisations* exist, not how
 * many rows land on any one organisation-scoped page -- every list view
 * this seed exercises reads one organisation at a time, so multiplying
 * the tenant count would balloon total row count and runtime without
 * making any single page's own query count any more revealing. This
 * remains, deliberately, a single-tenant volume/N+1 stress test, not an
 * attempt to simulate global concurrent multi-tenant traffic -- that
 * needs real load-testing tooling against a horizontally-scaled
 * deployment (backlog item #7), which no amount of local seeding
 * substitutes for.
 */
class SyntheticLoadSeeder extends Seeder
{
    private const TAXPAYER_COUNT = 20;

    private const USERS_PER_TAXPAYER = 5;

    private const NAMRA_STAFF_COUNT = 10;

    private const INVOICES_TOTAL = 75000;

    private const EXPENSES_TOTAL = 30000;

    private const AUDIT_CASES_TOTAL = 7500;

    private const EVIDENCE_PER_CASE = 4;

    private const DOCUMENTS_TOTAL = 15000;

    private const FIXED_ASSETS_TOTAL = 3000;

    private const TARGET_ORG_PARTIES = 3000;

    private const TARGET_ORG_QUOTATIONS = 3000;

    private const TARGET_ORG_PROJECTS = 3000;

    private const TARGET_ORG_LEDGER_EXPENSES = 3000;

    public function run(): void
    {
        $now = now();
        $taxpayerIds = [];
        $organisationIds = [];
        $userIdsByTaxpayer = [];
        $allTaxpayerUserIds = [];

        $this->command?->info('Synthetic load seed: taxpayers/organisations/capabilities/users...');
        for ($i = 1; $i <= self::TAXPAYER_COUNT; $i++) {
            $vatNumber = sprintf('VAT-LOAD-%04d', $i);
            $taxpayerId = (string) Str::uuid();
            $organisationId = (string) Str::uuid();
            $taxpayerIds[] = $taxpayerId;
            $organisationIds[] = $organisationId;

            DB::table('taxpayers')->insert([
                'id' => $taxpayerId, 'vat_number' => $vatNumber, 'tin' => "TIN-LOAD-{$i}",
                'legal_name' => "Load Test Trading {$i} (Pty) Ltd", 'trading_name' => "Load Test {$i}",
                'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE', 'return_frequency' => 'MONTHLY',
                'address' => "{$i} Synthetic Street, Windhoek", 'email' => "finance-{$i}@load-test.test",
                'created_at' => $now,
            ]);
            DB::table('organisations')->insert([
                'id' => $organisationId, 'taxpayer_id' => $taxpayerId, 'legal_name' => "Load Test Trading {$i} (Pty) Ltd",
                'trading_name' => "Load Test {$i}", 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('organisation_capabilities')->insert([
                ['id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'capability' => 'SELLER', 'status' => 'ACTIVE', 'effective_from' => $now->copy()->subYear(), 'effective_to' => null, 'approved_by' => null, 'created_at' => $now],
                ['id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'capability' => 'BUYER', 'status' => 'ACTIVE', 'effective_from' => $now->copy()->subYear(), 'effective_to' => null, 'approved_by' => null, 'created_at' => $now],
            ]);

            $roles = ['TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'TAXPAYER_ACCOUNTANT', 'TAXPAYER_STAFF', 'TAXPAYER_VIEWER'];
            $userIdsByTaxpayer[$taxpayerId] = [];
            $userRows = [];
            for ($u = 0; $u < self::USERS_PER_TAXPAYER; $u++) {
                $userId = (string) Str::uuid();
                $userIdsByTaxpayer[$taxpayerId][] = $userId;
                $allTaxpayerUserIds[] = $userId;
                $userRows[] = [
                    'id' => $userId, 'name' => "Load Test User {$i}-{$u}", 'email' => "load-{$i}-{$u}@load-test.test",
                    'password' => Hash::make('password'), 'role' => $roles[$u], 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('users')->insert($userRows);
        }

        $namraStaffIds = [];
        $namraRows = [];
        for ($n = 1; $n <= self::NAMRA_STAFF_COUNT; $n++) {
            $id = (string) Str::uuid();
            $namraStaffIds[] = $id;
            $namraRows[] = [
                'id' => $id, 'name' => "Load Test NamRA Staff {$n}", 'email' => "namra-load-{$n}@load-test.test",
                'password' => Hash::make('password'), 'role' => $n % 2 === 0 ? 'NAMRA_VAT_AUDITOR' : 'NAMRA_COMPLIANCE_OFFICER',
                'taxpayer_id' => null, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('users')->insert($namraRows);

        $this->command?->info('Synthetic load seed: invoices + invoice_lines ('.self::INVOICES_TOTAL.')...');
        $invoiceRows = [];
        $lineRows = [];
        for ($i = 0; $i < self::INVOICES_TOTAL; $i++) {
            $supplierIdx = $i % self::TAXPAYER_COUNT;
            $customerIdx = ($i + 1) % self::TAXPAYER_COUNT;
            $supplierId = $taxpayerIds[$supplierIdx];
            $customerId = $taxpayerIds[$customerIdx];
            $invoiceId = (string) Str::uuid();
            $issueDate = $now->copy()->subDays($i % 730)->toDateString();
            $netCents = 50_000 + ($i % 200) * 1_000;

            $invoiceRows[] = [
                'id' => $invoiceId, 'invoice_number' => sprintf('INV-LOAD-%06d', $i),
                'document_type' => 'TAX_INVOICE', 'source_system' => 'load-seed', 'source_document_id' => "load-doc-{$i}",
                'supplier_taxpayer_id' => $supplierId, 'supplier_name' => "Load Test Trading {$supplierIdx}", 'supplier_vat_number' => sprintf('VAT-LOAD-%04d', $supplierIdx + 1),
                'customer_taxpayer_id' => $customerId, 'customer_name' => "Load Test Trading {$customerIdx}", 'customer_vat_number' => sprintf('VAT-LOAD-%04d', $customerIdx + 1),
                'issue_date' => $issueDate, 'currency' => 'NAD',
                'line_net_cents' => $netCents, 'tax_cents' => 0, 'total_cents' => $netCents,
                'status' => 'MATCHED', 'risk_level' => 'LOW', 'payload_hash' => hash('sha256', "load-{$i}"),
                'transaction_id' => (string) Str::uuid(), 'certificate_id' => (string) Str::uuid(),
                'verification_token' => 'vfy_load_'.Str::random(28),
                'created_at' => $issueDate, 'certified_at' => $issueDate,
            ];
            $lineRows[] = [
                'id' => (string) Str::uuid(), 'invoice_id' => $invoiceId, 'line_number' => 1,
                'description' => 'Synthetic load-test goods', 'quantity' => '1', 'unit_code' => 'EA',
                'unit_price_cents' => $netCents, 'net_amount_cents' => $netCents, 'tax_rate_bps' => 0,
                'tax_category' => 'ZERO_RATED', 'tax_amount_cents' => 0, 'vat_rule_id' => null,
            ];

            if (count($invoiceRows) >= 500) {
                DB::table('invoices')->insert($invoiceRows);
                DB::table('invoice_lines')->insert($lineRows);
                $invoiceRows = [];
                $lineRows = [];
            }
        }
        if ($invoiceRows) {
            DB::table('invoices')->insert($invoiceRows);
            DB::table('invoice_lines')->insert($lineRows);
        }

        $this->command?->info('Synthetic load seed: expense categories + expenses ('.self::EXPENSES_TOTAL.')...');
        $categoryIdsByOrg = [];
        foreach ($organisationIds as $orgId) {
            $catId = (string) Str::uuid();
            $categoryIdsByOrg[$orgId] = $catId;
            DB::table('expense_categories')->insert([
                'id' => $catId, 'organisation_id' => $orgId, 'code' => 'LOAD', 'name' => 'Load Test Category',
                'default_tax_category' => 'STANDARD', 'requires_receipt' => false, 'status' => 'ACTIVE', 'created_at' => $now,
            ]);
        }
        $expenseRows = [];
        for ($i = 0; $i < self::EXPENSES_TOTAL; $i++) {
            $orgIdx = $i % self::TAXPAYER_COUNT;
            $orgId = $organisationIds[$orgIdx];
            $userId = $userIdsByTaxpayer[$taxpayerIds[$orgIdx]][0];
            $netCents = 10_000 + ($i % 500) * 100;
            $taxCents = (int) round($netCents * 0.15);
            $expenseRows[] = [
                'id' => (string) Str::uuid(), 'organisation_id' => $orgId, 'branch_id' => null,
                'category_id' => $categoryIdsByOrg[$orgId], 'supplier_party_id' => null, 'project_id' => null,
                'expense_number' => sprintf('EXP-LOAD-%06d', $i), 'expense_date' => $now->copy()->subDays($i % 365)->toDateString(),
                'description' => 'Synthetic load-test expense', 'currency' => 'NAD',
                'net_cents' => $netCents, 'tax_cents' => $taxCents, 'total_cents' => $netCents + $taxCents,
                'status' => 'APPROVED', 'receipt_document_id' => null, 'created_by' => $userId, 'approved_by' => $userId,
                'created_at' => $now, 'approved_at' => $now, 'rejection_reason' => null,
            ];
            if (count($expenseRows) >= 500) {
                DB::table('expenses')->insert($expenseRows);
                $expenseRows = [];
            }
        }
        if ($expenseRows) {
            DB::table('expenses')->insert($expenseRows);
        }

        $this->command?->info('Synthetic load seed: audit cases + evidence ('.self::AUDIT_CASES_TOTAL.')...');
        $caseTypes = ['DESK_REVIEW', 'VAT_AUDIT', 'REFUND_VERIFICATION', 'INVESTIGATION'];
        $caseRows = [];
        $evidenceRows = [];
        for ($i = 0; $i < self::AUDIT_CASES_TOTAL; $i++) {
            $orgIdx = $i % self::TAXPAYER_COUNT;
            $caseId = (string) Str::uuid();
            $officer = $namraStaffIds[$i % self::NAMRA_STAFF_COUNT];
            $caseRows[] = [
                'id' => $caseId, 'case_number' => sprintf('CASE-LOAD-%06d', $i), 'organisation_id' => $organisationIds[$orgIdx],
                'taxpayer_id' => $taxpayerIds[$orgIdx], 'case_type' => $caseTypes[$i % 4], 'title' => 'Synthetic load-test case',
                'opening_reason' => 'Load-test volume seeding for query-performance measurement.', 'risk_tier' => 'MEDIUM',
                'status' => 'OPEN', 'assigned_officer_id' => $officer, 'opened_by' => $officer,
                'opened_at' => $now->copy()->subDays($i % 400), 'updated_at' => $now, 'closed_at' => null,
                'suspended_from_status' => null, 'appeal_reference' => null, 'appeal_linked_at' => null,
            ];
            for ($e = 0; $e < self::EVIDENCE_PER_CASE; $e++) {
                $evidenceRows[] = [
                    'id' => (string) Str::uuid(), 'audit_case_id' => $caseId, 'evidence_type' => 'EXTERNAL_RECORD',
                    'source_resource_type' => 'OTHER', 'source_resource_id' => "load-evidence-{$i}-{$e}", 'document_id' => null,
                    'checksum_sha256' => hash('sha256', "load-evidence-{$i}-{$e}"), 'description' => 'Synthetic load-test evidence citation.',
                    'status' => 'PRESERVED', 'added_by' => $officer, 'added_at' => $now, 'previous_version_id' => null, 'legal_hold' => false,
                ];
            }
            if (count($caseRows) >= 250) {
                DB::table('audit_cases')->insert($caseRows);
                DB::table('audit_evidence')->insert($evidenceRows);
                $caseRows = [];
                $evidenceRows = [];
            }
        }
        if ($caseRows) {
            DB::table('audit_cases')->insert($caseRows);
            DB::table('audit_evidence')->insert($evidenceRows);
        }

        $this->command?->info('Synthetic load seed: documents ('.self::DOCUMENTS_TOTAL.')...');
        $documentRows = [];
        for ($i = 0; $i < self::DOCUMENTS_TOTAL; $i++) {
            $orgIdx = $i % self::TAXPAYER_COUNT;
            $userId = $userIdsByTaxpayer[$taxpayerIds[$orgIdx]][0];
            $documentRows[] = [
                'id' => (string) Str::uuid(), 'organisation_id' => $organisationIds[$orgIdx], 'owner_domain' => 'EXPENSE',
                'owner_resource_id' => (string) Str::uuid(), 'object_key' => "load-test/{$i}/".Str::random(20).'.pdf',
                'file_name' => "load-test-document-{$i}.pdf", 'content_type' => 'application/pdf', 'size_bytes' => 102_400,
                'checksum_sha256' => hash('sha256', "load-doc-{$i}"), 'classification' => 'INTERNAL', 'scan_status' => 'CLEAN',
                'status' => 'ACTIVE', 'uploaded_by' => $userId, 'uploaded_at' => $now, 'retained_until' => null,
                'legal_hold' => false, 'scanned_by' => $userId, 'scanned_at' => $now, 'supersedes_document_id' => null,
            ];
            if (count($documentRows) >= 500) {
                DB::table('document_metadata')->insert($documentRows);
                $documentRows = [];
            }
        }
        if ($documentRows) {
            DB::table('document_metadata')->insert($documentRows);
        }

        $this->command?->info('Synthetic load seed: fixed assets ('.self::FIXED_ASSETS_TOTAL.')...');
        $assetRows = [];
        for ($i = 0; $i < self::FIXED_ASSETS_TOTAL; $i++) {
            $orgIdx = $i % self::TAXPAYER_COUNT;
            $userId = $userIdsByTaxpayer[$taxpayerIds[$orgIdx]][0];
            $assetRows[] = [
                'id' => (string) Str::uuid(), 'organisation_id' => $organisationIds[$orgIdx],
                'asset_class' => $i % 2 === 0 ? 'MOVABLE' : 'IMMOVABLE', 'asset_code' => sprintf('ASSET-LOAD-%06d', $i),
                'category' => 'EQUIPMENT', 'description' => 'Synthetic load-test asset', 'serial_or_registration_number' => null,
                'location_or_address' => 'Windhoek', 'custodian_employee_id' => null, 'acquisition_date' => $now->copy()->subDays($i % 1000)->toDateString(),
                'acquisition_cost_cents' => 500_000, 'current_value_cents' => 400_000, 'status' => 'ACTIVE',
                'disposal_reason' => null, 'disposed_at' => null, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ];
            if (count($assetRows) >= 500) {
                DB::table('fixed_assets')->insert($assetRows);
                $assetRows = [];
            }
        }
        if ($assetRows) {
            DB::table('fixed_assets')->insert($assetRows);
        }

        $this->seedLedgerAndProjectVolume($taxpayerIds[0], $organisationIds[0], $userIdsByTaxpayer[$taxpayerIds[0]][0], $now);

        $this->command?->info('Synthetic load seed complete: '.self::TAXPAYER_COUNT.' taxpayers, '.(self::TAXPAYER_COUNT * self::USERS_PER_TAXPAYER + self::NAMRA_STAFF_COUNT).' users, '.self::INVOICES_TOTAL.' invoices, '.self::EXPENSES_TOTAL.' expenses, '.self::AUDIT_CASES_TOTAL.' audit cases ('.(self::AUDIT_CASES_TOTAL * self::EVIDENCE_PER_CASE).' evidence rows), '.self::DOCUMENTS_TOTAL.' documents, '.self::FIXED_ASSETS_TOTAL.' fixed assets, plus '.self::TARGET_ORG_PARTIES.' business parties/'.self::TARGET_ORG_QUOTATIONS.' quotations/'.self::TARGET_ORG_PROJECTS.' projects/'.self::TARGET_ORG_LEDGER_EXPENSES.' supplier-tagged expenses on the target organisation for ledger/project-management volume testing.');
    }

    /**
     * Concentrated on one organisation (see this class's own doc comment
     * for why): 200 business parties (customers/suppliers/service
     * providers), 200 quotations (a mix of statuses, half CONVERTED with
     * a real linked invoice, a handful of those further corrected with a
     * real credit or debit note), 200 projects across PLANNED/ACTIVE/
     * COMPLETED each with a budget, several costs, and (for about half)
     * a real REVENUE journal posting, plus 200 supplier-tagged expenses
     * for the Supplier Ledger. Bulk `DB::table()->insert()` throughout,
     * same as the rest of this seeder -- these are schema-valid rows for
     * read-path volume testing, not a real lifecycle walked through the
     * service layer.
     */
    private function seedLedgerAndProjectVolume(string $taxpayerId, string $organisationId, string $userId, \Illuminate\Support\Carbon $now): void
    {
        $this->command?->info('Synthetic load seed: business parties/quotations/projects/ledger expenses on the target organisation...');

        $partyIds = ['CUSTOMER' => [], 'SUPPLIER' => [], 'SERVICE_PROVIDER' => []];
        $partyRows = [];
        $relationshipRows = [];
        $relationshipsByCount = ['CUSTOMER' => (int) (self::TARGET_ORG_PARTIES * 0.5), 'SUPPLIER' => (int) (self::TARGET_ORG_PARTIES * 0.35)];
        $relationshipsByCount['SERVICE_PROVIDER'] = self::TARGET_ORG_PARTIES - $relationshipsByCount['CUSTOMER'] - $relationshipsByCount['SUPPLIER'];
        $partyIndex = 0;
        foreach ($relationshipsByCount as $relationship => $count) {
            for ($i = 0; $i < $count; $i++) {
                $partyId = (string) Str::uuid();
                $partyIds[$relationship][] = $partyId;
                $partyRows[] = [
                    'id' => $partyId, 'organisation_id' => $organisationId, 'display_name' => "Load Test {$relationship} Party {$partyIndex}",
                    'legal_name' => null, 'vat_number' => sprintf('VAT-LOADPARTY-%05d', $partyIndex), 'tin' => null, 'email' => null, 'phone' => null, 'address' => null,
                    'source_system' => 'load-seed', 'source_party_id' => "load-party-{$partyIndex}", 'status' => 'ACTIVE',
                    'created_at' => $now, 'updated_at' => $now,
                ];
                $relationshipRows[] = [
                    'id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'party_id' => $partyId, 'relationship' => $relationship,
                    'status' => 'ACTIVE', 'effective_from' => $now->copy()->subYear(), 'effective_to' => null, 'created_at' => $now,
                ];
                $partyIndex++;
            }
        }
        DB::table('business_parties')->insert($partyRows);
        DB::table('party_relationships')->insert($relationshipRows);

        $revenueAccountId = (string) Str::uuid();
        DB::table('chart_of_accounts')->insert([
            'id' => $revenueAccountId, 'organisation_id' => $organisationId, 'code' => 'LOAD-REV', 'name' => 'Load Test Revenue',
            'account_type' => 'REVENUE', 'currency' => 'NAD', 'control_type' => null, 'status' => 'ACTIVE', 'created_at' => $now,
        ]);
        $bankAccountId = (string) Str::uuid();
        DB::table('chart_of_accounts')->insert([
            'id' => $bankAccountId, 'organisation_id' => $organisationId, 'code' => 'LOAD-BANK', 'name' => 'Load Test Bank',
            'account_type' => 'ASSET', 'currency' => 'NAD', 'control_type' => null, 'status' => 'ACTIVE', 'created_at' => $now,
        ]);

        $quotationRows = [];
        $quotationLineRows = [];
        $invoiceRows = [];
        $invoiceLineRows = [];
        $correctionRows = [];
        $statuses = ['DRAFT', 'ISSUED', 'ACCEPTED', 'CONVERTED', 'REJECTED', 'EXPIRED'];
        for ($i = 0; $i < self::TARGET_ORG_QUOTATIONS; $i++) {
            $customerPartyId = $partyIds['CUSTOMER'][$i % count($partyIds['CUSTOMER'])];
            $quotationId = (string) Str::uuid();
            $issueDate = $now->copy()->subDays($i % 400)->toDateString();
            $netCents = 80_000 + ($i % 100) * 1_000;
            $taxCents = (int) round($netCents * 0.15);
            $totalCents = $netCents + $taxCents;
            $status = $i % 2 === 0 ? 'CONVERTED' : $statuses[$i % count($statuses)];

            $convertedInvoiceId = null;
            if ($status === 'CONVERTED') {
                $convertedInvoiceId = (string) Str::uuid();
                $invoiceRows[] = [
                    'id' => $convertedInvoiceId, 'invoice_number' => sprintf('INV-LOADQ-%06d', $i),
                    'document_type' => 'TAX_INVOICE', 'source_system' => 'VAT-MSA-QUOTATION', 'source_document_id' => $quotationId,
                    'supplier_taxpayer_id' => $taxpayerId, 'supplier_name' => 'Load Test Trading 1', 'supplier_vat_number' => 'VAT-LOAD-0001',
                    'customer_taxpayer_id' => null, 'customer_name' => "Load Test CUSTOMER Party {$i}", 'customer_vat_number' => sprintf('VAT-LOADPARTY-%05d', $i % count($partyIds['CUSTOMER'])),
                    'issue_date' => $issueDate, 'currency' => 'NAD',
                    'line_net_cents' => $netCents, 'tax_cents' => $taxCents, 'total_cents' => $totalCents,
                    'status' => 'MATCHED', 'risk_level' => 'LOW', 'payload_hash' => hash('sha256', "load-quote-invoice-{$i}"),
                    'transaction_id' => (string) Str::uuid(), 'certificate_id' => (string) Str::uuid(),
                    'verification_token' => 'vfy_loadq_'.Str::random(28),
                    'created_at' => $issueDate, 'certified_at' => $issueDate,
                ];
                $invoiceLineRows[] = [
                    'id' => (string) Str::uuid(), 'invoice_id' => $convertedInvoiceId, 'line_number' => 1,
                    'description' => 'Synthetic load-test consulting services', 'quantity' => '1', 'unit_code' => 'EA',
                    'unit_price_cents' => $netCents, 'net_amount_cents' => $netCents, 'tax_rate_bps' => 1500,
                    'tax_category' => 'STANDARD', 'tax_amount_cents' => $taxCents, 'vat_rule_id' => null,
                ];

                // Every fifth converted quotation's invoice gets a real credit note against it,
                // exercising QuotationService::crossReference()'s own batched InvoiceCorrection read.
                if ($i % 10 === 0) {
                    $creditCents = -1 * (int) round($netCents * 0.2);
                    $creditTaxCents = (int) round($creditCents * 0.15);
                    $creditInvoiceId = (string) Str::uuid();
                    $invoiceRows[] = [
                        'id' => $creditInvoiceId, 'invoice_number' => sprintf('CN-LOADQ-%06d', $i),
                        'document_type' => 'CREDIT_NOTE', 'source_system' => 'load-seed', 'source_document_id' => "load-cn-{$i}",
                        'supplier_taxpayer_id' => $taxpayerId, 'supplier_name' => 'Load Test Trading 1', 'supplier_vat_number' => 'VAT-LOAD-0001',
                        'customer_taxpayer_id' => null, 'customer_name' => "Load Test CUSTOMER Party {$i}", 'customer_vat_number' => sprintf('VAT-LOADPARTY-%05d', $i % count($partyIds['CUSTOMER'])),
                        'issue_date' => $issueDate, 'currency' => 'NAD',
                        'line_net_cents' => $creditCents, 'tax_cents' => $creditTaxCents, 'total_cents' => $creditCents + $creditTaxCents,
                        'status' => 'MATCHED', 'risk_level' => 'LOW', 'payload_hash' => hash('sha256', "load-cn-{$i}"),
                        'transaction_id' => (string) Str::uuid(), 'certificate_id' => (string) Str::uuid(),
                        'verification_token' => 'vfy_loadcn_'.Str::random(28),
                        'created_at' => $issueDate, 'certified_at' => $issueDate,
                    ];
                    $correctionRows[] = [
                        'id' => (string) Str::uuid(), 'original_invoice_id' => $convertedInvoiceId, 'correction_invoice_id' => $creditInvoiceId,
                        'correction_type' => 'CREDIT_NOTE', 'reason_code' => 'PRICING_ERROR', 'reason' => 'Synthetic load-test pricing correction.',
                        'status' => 'ACTIVE', 'created_by' => $userId, 'created_at' => $now,
                    ];
                }
            }

            $quotationRows[] = [
                'id' => $quotationId, 'organisation_id' => $organisationId, 'branch_id' => null, 'customer_party_id' => $customerPartyId,
                'quotation_number' => sprintf('QUO-LOAD-%06d', $i), 'currency' => 'NAD', 'issue_date' => $issueDate,
                'valid_until' => $now->copy()->addDays(30)->toDateString(), 'status' => $status,
                'subtotal_cents' => $netCents, 'tax_cents' => $taxCents, 'total_cents' => $totalCents, 'notes' => null,
                'created_by' => $userId, 'approved_by' => null, 'accepted_at' => in_array($status, ['ACCEPTED', 'CONVERTED'], true) ? $now : null,
                'converted_invoice_id' => $convertedInvoiceId, 'created_at' => $now, 'updated_at' => $now,
            ];
            $quotationLineRows[] = [
                'id' => (string) Str::uuid(), 'quotation_id' => $quotationId, 'line_number' => 1, 'product_id' => null,
                'description' => 'Synthetic load-test consulting services', 'quantity_micros' => 1_000_000, 'unit_code' => 'EA',
                'unit_price_cents' => $netCents, 'net_amount_cents' => $netCents, 'tax_category' => 'STANDARD',
                'tax_rate_bps' => 1500, 'tax_amount_cents' => $taxCents,
            ];

            if (count($quotationRows) >= 500) {
                DB::table('quotations')->insert($quotationRows);
                DB::table('quotation_lines')->insert($quotationLineRows);
                if ($invoiceRows) {
                    DB::table('invoices')->insert($invoiceRows);
                    DB::table('invoice_lines')->insert($invoiceLineRows);
                }
                if ($correctionRows) {
                    DB::table('invoice_corrections')->insert($correctionRows);
                }
                $quotationRows = $quotationLineRows = $invoiceRows = $invoiceLineRows = $correctionRows = [];
            }
        }
        if ($quotationRows) {
            DB::table('quotations')->insert($quotationRows);
            DB::table('quotation_lines')->insert($quotationLineRows);
        }
        if ($invoiceRows) {
            DB::table('invoices')->insert($invoiceRows);
            DB::table('invoice_lines')->insert($invoiceLineRows);
        }
        if ($correctionRows) {
            DB::table('invoice_corrections')->insert($correctionRows);
        }

        $projectRows = [];
        $budgetRows = [];
        $costRows = [];
        $journalEntryRows = [];
        $journalLineRows = [];
        $projectStatuses = ['PLANNED', 'ACTIVE', 'ACTIVE', 'COMPLETED'];
        for ($i = 0; $i < self::TARGET_ORG_PROJECTS; $i++) {
            $projectId = (string) Str::uuid();
            $status = $projectStatuses[$i % count($projectStatuses)];
            $customerPartyId = $partyIds['CUSTOMER'][$i % count($partyIds['CUSTOMER'])];
            $budgetCents = 500_000 + ($i % 50) * 10_000;
            $approvedCents = (int) round($budgetCents * 0.9);

            $projectRows[] = [
                'id' => $projectId, 'organisation_id' => $organisationId, 'code' => sprintf('PRJ-LOAD-%06d', $i), 'name' => "Synthetic Load Project {$i}",
                'customer_party_id' => $customerPartyId, 'manager_user_id' => $userId, 'currency' => 'NAD',
                'start_date' => $now->copy()->subDays(($i % 300) + 30)->toDateString(), 'end_date' => null, 'status' => $status,
                'created_at' => $now, 'updated_at' => $now,
            ];
            $budgetRows[] = [
                'id' => (string) Str::uuid(), 'project_id' => $projectId, 'category' => 'TOTAL', 'amount_cents' => $budgetCents,
                'approved_amount_cents' => $i % 3 === 0 ? 0 : $approvedCents, 'status' => $i % 3 === 0 ? 'PROPOSED' : 'APPROVED',
                'approved_by' => $i % 3 === 0 ? null : $userId, 'approved_at' => $i % 3 === 0 ? null : $now, 'created_at' => $now,
            ];
            for ($c = 0; $c < 3; $c++) {
                $costRows[] = [
                    'id' => (string) Str::uuid(), 'project_id' => $projectId, 'cost_type' => 'MANUAL', 'source_id' => "load-cost-{$i}-{$c}",
                    'amount_cents' => 20_000 + ($c * 5_000), 'currency' => 'NAD', 'description' => 'Synthetic load-test cost',
                    'occurred_at' => $now->copy()->subDays($c * 10)->toDateString(), 'created_by' => $userId, 'created_at' => $now,
                ];
            }
            if ($i % 2 === 0) {
                $journalEntryId = (string) Str::uuid();
                $revenueCents = (int) round($budgetCents * 0.6);
                $journalEntryRows[] = [
                    'id' => $journalEntryId, 'organisation_id' => $organisationId, 'journal_number' => sprintf('JRN-LOAD-%06d', $i),
                    'journal_date' => $now->toDateString(), 'reference' => null, 'description' => 'Synthetic load-test project revenue',
                    'currency' => 'NAD', 'status' => 'POSTED', 'source_type' => 'MANUAL', 'source_id' => null,
                    'created_by' => $userId, 'posted_by' => $userId, 'created_at' => $now, 'posted_at' => $now, 'reverses_journal_entry_id' => null,
                ];
                $journalLineRows[] = [
                    'id' => (string) Str::uuid(), 'journal_entry_id' => $journalEntryId, 'line_number' => 1, 'account_id' => $bankAccountId,
                    'branch_id' => null, 'project_id' => null, 'description' => 'Cash received', 'debit_cents' => $revenueCents, 'credit_cents' => 0, 'tax_code' => null,
                ];
                $journalLineRows[] = [
                    'id' => (string) Str::uuid(), 'journal_entry_id' => $journalEntryId, 'line_number' => 2, 'account_id' => $revenueAccountId,
                    'branch_id' => null, 'project_id' => $projectId, 'description' => 'Project revenue', 'debit_cents' => 0, 'credit_cents' => $revenueCents, 'tax_code' => null,
                ];
            }

            if (count($projectRows) >= 500) {
                DB::table('projects')->insert($projectRows);
                DB::table('project_budgets')->insert($budgetRows);
                DB::table('project_costs')->insert($costRows);
                if ($journalEntryRows) {
                    DB::table('journal_entries')->insert($journalEntryRows);
                    DB::table('journal_lines')->insert($journalLineRows);
                }
                $projectRows = $budgetRows = $costRows = $journalEntryRows = $journalLineRows = [];
            }
        }
        if ($projectRows) {
            DB::table('projects')->insert($projectRows);
            DB::table('project_budgets')->insert($budgetRows);
            DB::table('project_costs')->insert($costRows);
        }
        if ($journalEntryRows) {
            DB::table('journal_entries')->insert($journalEntryRows);
            DB::table('journal_lines')->insert($journalLineRows);
        }

        $categoryId = (string) Str::uuid();
        DB::table('expense_categories')->insert([
            'id' => $categoryId, 'organisation_id' => $organisationId, 'code' => 'LOAD-LEDGER', 'name' => 'Load Test Ledger Category',
            'default_tax_category' => 'STANDARD', 'requires_receipt' => false, 'status' => 'ACTIVE', 'created_at' => $now,
        ]);
        $expenseRows = [];
        for ($i = 0; $i < self::TARGET_ORG_LEDGER_EXPENSES; $i++) {
            $supplierPartyId = $partyIds['SUPPLIER'][$i % count($partyIds['SUPPLIER'])];
            $netCents = 15_000 + ($i % 300) * 100;
            $taxCents = (int) round($netCents * 0.15);
            $status = $i % 5 === 0 ? 'SUBMITTED' : ($i % 11 === 0 ? 'REJECTED' : 'APPROVED');
            $expenseRows[] = [
                'id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'branch_id' => null,
                'category_id' => $categoryId, 'supplier_party_id' => $supplierPartyId, 'project_id' => null,
                'expense_number' => sprintf('EXP-LOADLEDGER-%06d', $i), 'expense_date' => $now->copy()->subDays($i % 365)->toDateString(),
                'description' => 'Synthetic load-test ledger expense', 'currency' => 'NAD',
                'net_cents' => $netCents, 'tax_cents' => $taxCents, 'total_cents' => $netCents + $taxCents,
                'status' => $status, 'receipt_document_id' => null, 'created_by' => $userId,
                'approved_by' => $status === 'APPROVED' ? $userId : null, 'created_at' => $now,
                'approved_at' => $status === 'APPROVED' ? $now : null, 'rejection_reason' => $status === 'REJECTED' ? 'Synthetic load-test rejection.' : null,
            ];
            if (count($expenseRows) >= 500) {
                DB::table('expenses')->insert($expenseRows);
                $expenseRows = [];
            }
        }
        if ($expenseRows) {
            DB::table('expenses')->insert($expenseRows);
        }
    }
}
