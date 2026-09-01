<?php

namespace Database\Seeders;

use App\Models\VatRule;
use Illuminate\Database\Seeder;

/**
 * Ported verbatim from db/runtime.ts's vat_rules seed rows (the "fails
 * closed rather than silently falling back to some default" bootstrap set)
 * -- the 5 real NamRA-approved rate/category bindings InvoiceService's
 * VAT-rule resolution (Module 2 Phase A) requires before any invoice can be
 * certified. IDs are kept as the source's own slugs, not random UUIDs, so a
 * re-seed is trivially idempotent and traceable back to the source.
 */
class VatRuleSeeder extends Seeder
{
    public function run(): void
    {
        $bootstrap = [
            'proposed_by' => 'SYSTEM_BOOTSTRAP',
            'proposed_at' => '2026-01-01T00:00:00Z',
            'approved_by' => 'SYSTEM_BOOTSTRAP',
            'approved_at' => '2026-01-01T00:00:00Z',
            'approval_reason' => 'Deployment bootstrap of the current statutory rate.',
            'status' => 'APPROVED',
            'version' => 1,
            'effective_from' => '2026-01-01',
            'country' => 'NA',
        ];

        $rules = [
            ['id' => 'vrule-standard-na', 'tax_category' => 'STANDARD', 'rate_bps' => 1500, 'proposal_reason' => 'Namibia standard VAT rate.'],
            ['id' => 'vrule-zero_rated-na', 'tax_category' => 'ZERO_RATED', 'rate_bps' => 0, 'proposal_reason' => 'Zero-rated supplies.'],
            ['id' => 'vrule-exempt-na', 'tax_category' => 'EXEMPT', 'rate_bps' => 0, 'proposal_reason' => 'Exempt supplies.'],
            ['id' => 'vrule-outside_scope-na', 'tax_category' => 'OUTSIDE_SCOPE', 'rate_bps' => 0, 'proposal_reason' => 'Outside-scope (non-supply) transactions.'],
            ['id' => 'vrule-reverse_charge-na', 'tax_category' => 'REVERSE_CHARGE', 'rate_bps' => 1500, 'proposal_reason' => 'Reverse-charge supplies (standard rate, liability shifted to the recipient).'],
        ];

        foreach ($rules as $rule) {
            VatRule::updateOrCreate(['id' => $rule['id']], array_merge($bootstrap, $rule));
        }
    }
}
