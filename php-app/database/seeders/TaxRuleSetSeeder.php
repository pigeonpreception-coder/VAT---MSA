<?php

namespace Database\Seeders;

use App\Models\TaxBoxMapping;
use App\Models\TaxRuleSet;
use Illuminate\Database\Seeder;

/**
 * Ported verbatim from db/runtime.ts's tax_rule_sets/tax_box_mappings seed
 * rows -- like VatRuleSeeder, a real functional prerequisite rather than
 * cosmetic demo data: VatLifecycleService::generateReturn has no command
 * path that can ever create a tax rule set (see that service's own doc
 * comment), so without this seed no VAT return can ever be generated in
 * either the source or this port. IDs are kept as the source's own slugs,
 * matching VatRuleSeeder's own convention.
 */
class TaxRuleSetSeeder extends Seeder
{
    public function run(): void
    {
        TaxRuleSet::updateOrCreate(['id' => 'taxrule-na-pilot-2026-1'], [
            'jurisdiction' => 'NA', 'version' => 'NA-VAT-PILOT-2026.1', 'effective_from' => '2026-01-01', 'effective_to' => null,
            'standard_rate_bps' => 1500, 'legal_authority_reference' => null, 'status' => 'PILOT_CONTROLLED',
            'approved_by' => null, 'approved_at' => null, 'created_at' => '2026-08-09 11:00:00',
        ]);

        $boxes = [
            ['id' => 'boxmap-output', 'box_code' => 'BOX_OUTPUT', 'label' => 'Output VAT', 'source_entry_type' => 'OUTPUT_VAT', 'direction' => 'CREDIT', 'formula' => 'SUM(eligible output VAT ledger entries)'],
            ['id' => 'boxmap-input', 'box_code' => 'BOX_INPUT', 'label' => 'Eligible input VAT', 'source_entry_type' => 'INPUT_VAT', 'direction' => 'DEBIT', 'formula' => 'SUM(matched eligible input VAT ledger entries)'],
            ['id' => 'boxmap-adjust', 'box_code' => 'BOX_ADJUST', 'label' => 'Approved net adjustments', 'source_entry_type' => 'ADJUSTMENT', 'direction' => 'SIGNED', 'formula' => 'SUM(approved adjustment effects)'],
            ['id' => 'boxmap-net', 'box_code' => 'BOX_NET', 'label' => 'Net VAT payable or refundable', 'source_entry_type' => 'CALCULATED', 'direction' => 'SIGNED', 'formula' => 'BOX_OUTPUT - BOX_INPUT + BOX_ADJUST'],
        ];
        foreach ($boxes as $box) {
            TaxBoxMapping::updateOrCreate(['id' => $box['id']], array_merge($box, [
                'tax_rule_set_id' => 'taxrule-na-pilot-2026-1', 'status' => 'ACTIVE',
            ]));
        }
    }
}
