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
 */
class SyntheticLoadSeeder extends Seeder
{
    private const TAXPAYER_COUNT = 20;

    private const USERS_PER_TAXPAYER = 5;

    private const NAMRA_STAFF_COUNT = 10;

    private const INVOICES_TOTAL = 5000;

    private const EXPENSES_TOTAL = 2000;

    private const AUDIT_CASES_TOTAL = 500;

    private const EVIDENCE_PER_CASE = 4;

    private const DOCUMENTS_TOTAL = 1000;

    private const FIXED_ASSETS_TOTAL = 200;

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

        $this->command?->info('Synthetic load seed complete: '.self::TAXPAYER_COUNT.' taxpayers, '.(self::TAXPAYER_COUNT * self::USERS_PER_TAXPAYER + self::NAMRA_STAFF_COUNT).' users, '.self::INVOICES_TOTAL.' invoices, '.self::EXPENSES_TOTAL.' expenses, '.self::AUDIT_CASES_TOTAL.' audit cases ('.(self::AUDIT_CASES_TOTAL * self::EVIDENCE_PER_CASE).' evidence rows), '.self::DOCUMENTS_TOTAL.' documents, '.self::FIXED_ASSETS_TOTAL.' fixed assets.');
    }
}
