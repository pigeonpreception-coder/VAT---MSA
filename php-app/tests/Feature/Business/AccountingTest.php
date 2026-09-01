<?php

namespace Tests\Feature\Business;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers Phase 10 (slice 2): accounting (App\Services\Business\
 * AccountingService, ported from postJournal/createAccount/
 * reverseJournalEntry/closeAccountingPeriod/getTrialBalance/
 * getFinancialStatements) -- Module 5 Phase C.
 */
class AccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
    private function makeOrganisation(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function createAccount(User $owner, string $code, string $type): string
    {
        $response = $this->actingAs($owner)->postJson('/api/v1/accounting/accounts', [
            'schema_version' => '1.0.0', 'code' => $code, 'name' => "Account {$code}", 'account_type' => $type, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-acct-'.$code]);

        return $response->json('resource.id');
    }

    private function journalPayload(string $debitAccountId, string $creditAccountId, int $amountCents = 100000, array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => '1.0.0', 'journal_number' => 'JRN-TEST-0001', 'journal_date' => '2026-09-01',
            'description' => 'Test journal entry', 'currency' => 'NAD', 'source_type' => 'MANUAL',
            'lines' => [
                ['account_id' => $debitAccountId, 'description' => 'Debit line', 'debit_cents' => $amountCents, 'credit_cents' => 0],
                ['account_id' => $creditAccountId, 'description' => 'Credit line', 'debit_cents' => 0, 'credit_cents' => $amountCents],
            ],
        ], $overrides);
    }

    public function test_an_account_can_be_created_and_a_duplicate_code_is_a_conflict(): void
    {
        $seller = $this->makeOrganisation('VAT-ACCT-0001');

        $response = $this->actingAs($seller['owner'])->postJson('/api/v1/accounting/accounts', [
            'schema_version' => '1.0.0', 'code' => 'BANK01', 'name' => 'Main Bank Account', 'account_type' => 'ASSET', 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-acct-bank01']);
        $response->assertStatus(201)->assertJsonPath('resource.code', 'BANK01');

        $duplicate = $this->actingAs($seller['owner'])->postJson('/api/v1/accounting/accounts', [
            'schema_version' => '1.0.0', 'code' => 'BANK01', 'name' => 'Another Bank Account', 'account_type' => 'ASSET', 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-acct-bank01-dup']);
        $duplicate->assertStatus(409);
    }

    public function test_a_balanced_journal_can_be_posted(): void
    {
        $seller = $this->makeOrganisation('VAT-ACCT-0002');
        $bank = $this->createAccount($seller['owner'], 'BANK', 'ASSET');
        $revenue = $this->createAccount($seller['owner'], 'REV', 'REVENUE');

        $response = $this->actingAs($seller['owner'])->postJson('/api/v1/accounting/journals', $this->journalPayload($bank, $revenue), ['Idempotency-Key' => 'test-idem-jrn-0001']);

        $response->assertStatus(201)->assertJsonPath('resource.status', 'POSTED')->assertJsonCount(2, 'resource.lines');
        $this->assertDatabaseHas('journal_entries', ['journal_number' => 'JRN-TEST-0001', 'status' => 'POSTED']);
        $this->assertDatabaseHas('audit_events', ['action' => 'JOURNAL_POSTED']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'JournalPosted']);
    }

    public function test_an_unbalanced_journal_is_rejected(): void
    {
        $seller = $this->makeOrganisation('VAT-ACCT-0003');
        $bank = $this->createAccount($seller['owner'], 'BANK', 'ASSET');
        $revenue = $this->createAccount($seller['owner'], 'REV', 'REVENUE');
        $payload = $this->journalPayload($bank, $revenue);
        $payload['lines'][1]['credit_cents'] = 50000; // debit 100000, credit 50000 -- unbalanced

        $response = $this->actingAs($seller['owner'])->postJson('/api/v1/accounting/journals', $payload, ['Idempotency-Key' => 'test-idem-jrn-unbal-0001']);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'JOURNAL_UNBALANCED');
        $this->assertDatabaseMissing('journal_entries', ['journal_number' => 'JRN-TEST-0001']);
    }

    public function test_a_journal_referencing_an_unowned_account_is_rejected(): void
    {
        $seller = $this->makeOrganisation('VAT-ACCT-0004');
        $other = $this->makeOrganisation('VAT-ACCT-0005');
        $bank = $this->createAccount($seller['owner'], 'BANK', 'ASSET');
        $foreignRevenue = $this->createAccount($other['owner'], 'REV', 'REVENUE');

        $response = $this->actingAs($seller['owner'])->postJson('/api/v1/accounting/journals', $this->journalPayload($bank, $foreignRevenue), ['Idempotency-Key' => 'test-idem-jrn-foreign-0001']);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('journal_entries', ['journal_number' => 'JRN-TEST-0001']);
    }

    public function test_a_posted_journal_can_be_reversed_and_a_reversal_cannot_be_reversed_twice(): void
    {
        $seller = $this->makeOrganisation('VAT-ACCT-0006');
        $bank = $this->createAccount($seller['owner'], 'BANK', 'ASSET');
        $revenue = $this->createAccount($seller['owner'], 'REV', 'REVENUE');
        $post = $this->actingAs($seller['owner'])->postJson('/api/v1/accounting/journals', $this->journalPayload($bank, $revenue), ['Idempotency-Key' => 'test-idem-jrn-rev-0001']);
        $journalId = $post->json('resource.id');

        $reversal = $this->actingAs($seller['owner'])->postJson("/api/v1/accounting/journals/{$journalId}/reversal", ['schema_version' => '1.0.0', 'reason' => 'Posted to the wrong account by mistake.'], ['Idempotency-Key' => 'test-idem-jrn-rev-0002']);

        $reversal->assertStatus(201)->assertJsonPath('resource.journal_number', 'JRN-TEST-0001-REV');
        $this->assertDatabaseHas('journal_entries', ['id' => $journalId, 'status' => 'REVERSED']);
        // Debit/credit are swapped on the reversal's lines.
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $reversal->json('resource.id'), 'account_id' => $bank, 'debit_cents' => 0, 'credit_cents' => 100000]);

        $secondReversal = $this->actingAs($seller['owner'])->postJson("/api/v1/accounting/journals/{$journalId}/reversal", ['schema_version' => '1.0.0', 'reason' => 'Trying to reverse the same entry again.'], ['Idempotency-Key' => 'test-idem-jrn-rev-0003']);
        $secondReversal->assertStatus(409);
    }

    public function test_closing_a_period_blocks_new_postings_into_it_and_is_idempotent(): void
    {
        $seller = $this->makeOrganisation('VAT-ACCT-0007');
        $bank = $this->createAccount($seller['owner'], 'BANK', 'ASSET');
        $revenue = $this->createAccount($seller['owner'], 'REV', 'REVENUE');
        // A past month so ClosePeriod's "cannot close before it has ended" check passes.
        $payload = $this->journalPayload($bank, $revenue, 100000, ['journal_date' => '2026-01-15']);
        $this->actingAs($seller['owner'])->postJson('/api/v1/accounting/journals', $payload, ['Idempotency-Key' => 'test-idem-jrn-close-0001'])->assertStatus(201);

        $close = $this->actingAs($seller['owner'])->postJson('/api/v1/accounting/periods/closure', ['schema_version' => '1.0.0', 'period_code' => '2026-01'], ['Idempotency-Key' => 'test-idem-close-0001']);
        $close->assertStatus(200)->assertJsonPath('resource.status', 'CLOSED');

        $blocked = $this->actingAs($seller['owner'])->postJson('/api/v1/accounting/journals', array_replace_recursive($payload, ['journal_number' => 'JRN-TEST-0002']), ['Idempotency-Key' => 'test-idem-jrn-close-0002']);
        $blocked->assertStatus(409);

        // Re-closing an already-closed period is an idempotent no-op success, not an error.
        $reclose = $this->actingAs($seller['owner'])->postJson('/api/v1/accounting/periods/closure', ['schema_version' => '1.0.0', 'period_code' => '2026-01'], ['Idempotency-Key' => 'test-idem-close-0002']);
        $reclose->assertStatus(200)->assertJsonPath('resource.status', 'CLOSED');
        $this->assertDatabaseCount('accounting_periods', 1);
    }

    public function test_the_trial_balance_stays_balanced_after_posting(): void
    {
        $seller = $this->makeOrganisation('VAT-ACCT-0008');
        $bank = $this->createAccount($seller['owner'], 'BANK', 'ASSET');
        $revenue = $this->createAccount($seller['owner'], 'REV', 'REVENUE');
        $this->actingAs($seller['owner'])->postJson('/api/v1/accounting/journals', $this->journalPayload($bank, $revenue), ['Idempotency-Key' => 'test-idem-jrn-tb-0001'])->assertStatus(201);

        $response = $this->actingAs($seller['owner'])->getJson('/api/v1/accounting/trial-balance?as_of=2026-09-30');

        $response->assertStatus(200)->assertJsonPath('balanced', true)->assertJsonPath('total_debit_cents', 100000)->assertJsonPath('total_credit_cents', 100000);
    }

    public function test_financial_statements_reflect_revenue_and_stay_balanced(): void
    {
        $seller = $this->makeOrganisation('VAT-ACCT-0009');
        $bank = $this->createAccount($seller['owner'], 'BANK', 'ASSET');
        $revenue = $this->createAccount($seller['owner'], 'REV', 'REVENUE');
        $this->actingAs($seller['owner'])->postJson('/api/v1/accounting/journals', $this->journalPayload($bank, $revenue), ['Idempotency-Key' => 'test-idem-jrn-fs-0001'])->assertStatus(201);

        $response = $this->actingAs($seller['owner'])->getJson('/api/v1/accounting/statements?from=2026-09-01&to=2026-09-30');

        $response->assertStatus(200)
            ->assertJsonPath('income_statement.revenue_cents', 100000)
            ->assertJsonPath('income_statement.net_income_cents', 100000)
            ->assertJsonPath('balance_sheet.assets_cents', 100000)
            ->assertJsonPath('balance_sheet.balanced', true);
    }

    public function test_a_viewer_without_accounting_post_is_denied(): void
    {
        $seller = $this->makeOrganisation('VAT-ACCT-0010');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer-acct@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $seller['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($viewer)->postJson('/api/v1/accounting/accounts', [
            'schema_version' => '1.0.0', 'code' => 'BANK', 'name' => 'Bank', 'account_type' => 'ASSET', 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-acct-viewer-0001']);

        $response->assertStatus(403);
    }
}
