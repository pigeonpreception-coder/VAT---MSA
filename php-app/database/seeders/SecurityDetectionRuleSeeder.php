<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ported verbatim from db/runtime.ts's SECURITY_SEED_STATEMENTS
 * security_detection_rules rows -- the fixed, code-versioned rule
 * catalogue App\Support\Security\SecurityEventRecorder::
 * evaluateDetectionRules() reads. IDs are the source's own stable seed
 * IDs (e.g. 'secrule-repeated-denials'), matching
 * AuthorityGovernanceSeeder's own precedent for reference data.
 *
 * secrule-audit-chain-breach is seeded for parity even though nothing in
 * this migration yet emits an AUDIT_CHAIN_BREAK event (no audit-chain
 * verification pass exists here) -- see
 * App\Support\Security\SecurityEventRecorder's own doc comment. It sits
 * dormant, matching the source's own rule, rather than being omitted.
 */
class SecurityDetectionRuleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rules = [
            [
                'id' => 'secrule-repeated-denials',
                'code' => 'REPEATED_AUTHORISATION_DENIALS',
                'name' => 'Repeated authorisation denials',
                'description' => 'Opens an incident when the same actor accumulates repeated access-denied events in a short window.',
                'event_type' => 'AUTHORISATION_DENIED',
                'group_by' => 'actor_id',
                'threshold_count' => 5,
                'window_minutes' => 15,
                'severity' => 'HIGH',
            ],
            [
                'id' => 'secrule-rate-limit-abuse',
                'code' => 'RATE_LIMIT_ABUSE',
                'name' => 'Rate limit abuse',
                'description' => 'Opens an incident when the same source repeatedly trips a rate limit in a short window.',
                'event_type' => 'RATE_LIMIT_EXCEEDED',
                'group_by' => 'source_token',
                'threshold_count' => 10,
                'window_minutes' => 10,
                'severity' => 'MEDIUM',
            ],
            [
                'id' => 'secrule-audit-chain-breach',
                'code' => 'AUDIT_CHAIN_INTEGRITY_BREACH',
                'name' => 'Audit chain integrity breach',
                'description' => 'Opens a CRITICAL incident the moment a chain-verification run finds a broken or tampered audit_events hash chain.',
                'event_type' => 'AUDIT_CHAIN_BREAK',
                'group_by' => 'actor_id',
                'threshold_count' => 1,
                'window_minutes' => 1440,
                'severity' => 'CRITICAL',
            ],
        ];

        foreach ($rules as $rule) {
            DB::table('security_detection_rules')->updateOrInsert(
                ['id' => $rule['id']],
                [
                    'code' => $rule['code'],
                    'name' => $rule['name'],
                    'description' => $rule['description'],
                    'event_type' => $rule['event_type'],
                    'group_by' => $rule['group_by'],
                    'threshold_count' => $rule['threshold_count'],
                    'window_minutes' => $rule['window_minutes'],
                    'severity' => $rule['severity'],
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                ],
            );
        }
    }
}
