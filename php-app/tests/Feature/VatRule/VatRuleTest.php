<?php

namespace Tests\Feature\VatRule;

use App\Models\User;
use App\Models\VatRule;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VatRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithStepUp;
use Tests\TestCase;

/**
 * Covers App\Services\VatRule\VatRuleService (ported from lib/data/vat-
 * rule-repository.ts's listVatRules/proposeVatRule/approveVatRule/
 * evaluateVatRule, Module 2 Phase A) -- the standalone VAT-rule evaluate/
 * propose/approve routes, the last narrow gap Phase 9 (invoices and VAT)
 * deferred. Built on VatRuleSeeder's own real seeded rules (the same 5
 * rows InvoiceCertificationTest exercises), not fixtures inserted directly.
 */
class VatRuleTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithStepUp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(VatRuleSeeder::class);
    }

    private function pilotAdmin(string $suffix = ''): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => "Pilot Admin{$suffix}", 'email' => 'pilot-admin-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_SUPPORT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function complianceOfficer(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Compliance Officer', 'email' => 'officer-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_COMPLIANCE_OFFICER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function proposalPayload(array $overrides = []): array
    {
        return array_replace([
            'tax_category' => 'STANDARD', 'rate_bps' => 1600, 'effective_from' => '2026-12-01',
            'reason' => 'Statutory rate increase per Government Gazette No. 8123.',
        ], $overrides);
    }

    public function test_proposing_and_approving_a_vat_rule_retires_the_previously_approved_rule(): void
    {
        $proposer = $this->pilotAdmin('-proposer');
        $approver = $this->pilotAdmin('-approver');

        $propose = $this->actingAs($proposer)
            ->withFreshStepUp()
            ->postJson('/api/v1/vat-rules', $this->proposalPayload(), ['Idempotency-Key' => 'propose-'.Str::random(20)]);
        $propose->assertStatus(201)->assertJsonPath('rule.status', 'DRAFT')->assertJsonPath('rule.version', 2);
        $ruleId = $propose->json('rule.id');

        $approve = $this->actingAs($approver)
            ->withFreshStepUp()
            ->postJson("/api/v1/vat-rules/{$ruleId}/approval", ['reason' => 'Verified against the gazette notice.'], ['Idempotency-Key' => 'approve-'.Str::random(20)]);
        $approve->assertStatus(200)->assertJsonPath('rule.status', 'APPROVED');

        $this->assertDatabaseHas('vat_rules', ['id' => $ruleId, 'status' => 'APPROVED']);
        $this->assertDatabaseHas('vat_rules', ['id' => 'vrule-standard-na', 'superseded_by' => $ruleId]);
        $retired = VatRule::findOrFail('vrule-standard-na');
        $this->assertSame('2026-12-01', $retired->effective_to->toDateString());
        $this->assertDatabaseHas('audit_events', ['action' => 'VAT_RULE_APPROVED', 'resource_id' => $ruleId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'VatRuleApproved']);
    }

    public function test_self_approval_is_denied(): void
    {
        $proposer = $this->pilotAdmin();
        $propose = $this->actingAs($proposer)
            ->withFreshStepUp()
            ->postJson('/api/v1/vat-rules', $this->proposalPayload(), ['Idempotency-Key' => 'propose-'.Str::random(20)]);
        $ruleId = $propose->json('rule.id');

        $selfApprove = $this->actingAs($proposer)
            ->withFreshStepUp()
            ->postJson("/api/v1/vat-rules/{$ruleId}/approval", ['reason' => 'Attempting to self-approve.'], ['Idempotency-Key' => 'approve-'.Str::random(20)]);
        $selfApprove->assertStatus(422)->assertJsonPath('errors.0.code', 'SELF_APPROVAL_DENIED');
    }

    public function test_approving_an_already_approved_rule_is_a_conflict(): void
    {
        $proposer = $this->pilotAdmin('-proposer');
        $approver = $this->pilotAdmin('-approver');
        $propose = $this->actingAs($proposer)
            ->withFreshStepUp()
            ->postJson('/api/v1/vat-rules', $this->proposalPayload(), ['Idempotency-Key' => 'propose-'.Str::random(20)]);
        $ruleId = $propose->json('rule.id');
        $this->actingAs($approver)
            ->withFreshStepUp()
            ->postJson("/api/v1/vat-rules/{$ruleId}/approval", ['reason' => 'First approval.'], ['Idempotency-Key' => 'approve-'.Str::random(20)])
            ->assertStatus(200);

        $second = $this->actingAs($approver)
            ->withFreshStepUp()
            ->postJson("/api/v1/vat-rules/{$ruleId}/approval", ['reason' => 'Second attempt.'], ['Idempotency-Key' => 'approve-'.Str::random(20)]);
        $second->assertStatus(409);
    }

    /**
     * Red-team punch list #8 (docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_
     * 2026-09-15.md): approve()'s DRAFT check ran before its transaction,
     * unguarded -- a concurrent approval race could corrupt the effective/
     * superseded chain of VAT rules feeding tax-rate calculation.
     */
    public function test_approving_a_vat_rule_that_races_a_concurrent_approval_is_rejected_not_silently_applied(): void
    {
        $proposer = $this->pilotAdmin('-proposer');
        $approver = $this->pilotAdmin('-approver');
        $propose = $this->actingAs($proposer)
            ->withFreshStepUp()
            ->postJson('/api/v1/vat-rules', $this->proposalPayload(), ['Idempotency-Key' => 'propose-'.Str::random(20)]);
        $ruleId = $propose->json('rule.id');

        $sabotaged = false;
        DB::listen(function ($query) use (&$sabotaged, $ruleId) {
            if ($sabotaged || ! str_contains($query->sql, 'from `vat_rules`')) {
                return;
            }
            $sabotaged = true;
            DB::table('vat_rules')->where('id', $ruleId)->update(['status' => 'APPROVED']);
        });

        $response = $this->actingAs($approver)
            ->withFreshStepUp()
            ->postJson("/api/v1/vat-rules/{$ruleId}/approval", ['reason' => 'Racing a concurrent approval.'], ['Idempotency-Key' => 'approve-'.Str::random(20)]);

        $response->assertStatus(409);
        // The loser of the race must never touch the previously approved rule it would have superseded.
        $this->assertDatabaseMissing('vat_rules', ['id' => 'vrule-standard-na', 'superseded_by' => $ruleId]);
    }

    public function test_a_rule_that_would_not_take_effect_after_the_current_approved_rule_is_rejected_on_approval(): void
    {
        $proposer = $this->pilotAdmin('-proposer');
        $approver = $this->pilotAdmin('-approver');
        // The seeded standard rule is effective_from 2026-01-01; a proposal
        // effective on or before that date must be rejected at approval time.
        $propose = $this->actingAs($proposer)
            ->withFreshStepUp()
            ->postJson('/api/v1/vat-rules', $this->proposalPayload(['effective_from' => '2026-01-01']), ['Idempotency-Key' => 'propose-'.Str::random(20)]);
        $ruleId = $propose->json('rule.id');

        $approve = $this->actingAs($approver)
            ->withFreshStepUp()
            ->postJson("/api/v1/vat-rules/{$ruleId}/approval", ['reason' => 'Attempting a backdated approval.'], ['Idempotency-Key' => 'approve-'.Str::random(20)]);
        $approve->assertStatus(422)->assertJsonPath('errors.0.code', 'EFFECTIVE_FROM_NOT_FORWARD');
    }

    public function test_evaluate_resolves_the_applicable_rule_and_fails_closed_when_none_is_bound(): void
    {
        $officer = $this->complianceOfficer();

        $resolved = $this->actingAs($officer)->getJson('/api/v1/vat-rules/evaluate?tax_category=STANDARD&date=2026-01-15');
        $resolved->assertStatus(200)
            ->assertJsonPath('evaluation.rule.id', 'vrule-standard-na')
            ->assertJsonPath('evaluation.rule.rate_bps', 1500);

        // OTHER is deliberately left unseeded (VatRuleSeeder's own doc comment) -- fails closed, no default.
        $unbound = $this->actingAs($officer)->getJson('/api/v1/vat-rules/evaluate?tax_category=OTHER&date=2026-01-15');
        $unbound->assertStatus(422)->assertJsonPath('errors.0.code', 'NO_APPROVED_VAT_RULE');
    }

    /**
     * Red-team punch list #9 (docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_
     * 2026-09-15.md): this endpoint sets the tax rate applied to every
     * invoice and has no Blade-form sibling at all (JSON-only), so it was
     * never previously fuzzed with malformed payloads by any prior pass.
     * Confirms `rate_bps` and `tax_category` are cleanly rejected as 422s
     * for a malformed JSON array/object, not silently coerced or a 500.
     */
    public function test_proposing_a_vat_rule_rejects_malformed_rate_bps_and_tax_category_cleanly(): void
    {
        $proposer = $this->pilotAdmin();

        $arrayRate = $this->actingAs($proposer)->withFreshStepUp()
            ->postJson('/api/v1/vat-rules', $this->proposalPayload(['rate_bps' => ['not', 'a', 'number']]), ['Idempotency-Key' => 'propose-fuzz-'.Str::random(20)]);
        $arrayRate->assertStatus(422)->assertJsonPath('errors.0.code', 'RATE_INVALID');

        $oversizedRate = $this->actingAs($proposer)->withFreshStepUp()
            ->postJson('/api/v1/vat-rules', $this->proposalPayload(['rate_bps' => '99999999999999999999999999999999']), ['Idempotency-Key' => 'propose-fuzz-'.Str::random(20)]);
        $oversizedRate->assertStatus(422)->assertJsonPath('errors.0.code', 'RATE_INVALID');

        $arrayCategory = $this->actingAs($proposer)->withFreshStepUp()
            ->postJson('/api/v1/vat-rules', $this->proposalPayload(['tax_category' => ['STANDARD']]), ['Idempotency-Key' => 'propose-fuzz-'.Str::random(20)]);
        $arrayCategory->assertStatus(422)->assertJsonPath('errors.0.code', 'TAX_CATEGORY_INVALID');

        $this->assertDatabaseCount('vat_rules', 5); // just VatRuleSeeder's own seeded rows -- nothing malformed ever got created.
    }

    public function test_listing_requires_read_permission_and_proposing_requires_manage_permission(): void
    {
        $officer = $this->complianceOfficer();

        $this->actingAs($officer)->getJson('/api/v1/vat-rules')->assertStatus(200);
        $this->actingAs($officer)
            ->withFreshStepUp()
            ->postJson('/api/v1/vat-rules', $this->proposalPayload(), ['Idempotency-Key' => 'propose-'.Str::random(20)])
            ->assertStatus(403);
    }

    public function test_proposal_replay_is_idempotent(): void
    {
        $proposer = $this->pilotAdmin();
        $key = 'propose-shared-'.Str::random(20);

        $first = $this->actingAs($proposer)
            ->withFreshStepUp()
            ->postJson('/api/v1/vat-rules', $this->proposalPayload(), ['Idempotency-Key' => $key]);
        $first->assertStatus(201);
        $replay = $this->actingAs($proposer)
            ->withFreshStepUp()
            ->postJson('/api/v1/vat-rules', $this->proposalPayload(), ['Idempotency-Key' => $key]);
        $replay->assertStatus(201)->assertJsonPath('rule.id', $first->json('rule.id'));

        $this->assertSame(1, VatRule::where('tax_category', 'STANDARD')->where('version', 2)->count());
    }
}
