<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ported verbatim from db/runtime.ts's PLATFORM_SEED_STATEMENTS
 * service_components rows -- deploy-time reference data PlatformSnapshotService
 * already reads for display, matching AuthorityGovernanceSeeder/
 * SecurityDetectionRuleSeeder's own precedent for this kind of fixed,
 * code-versioned catalogue. IDs are the source's own stable, human-readable
 * seed IDs (e.g. 'component-payment'), not generated UUIDs -- see
 * App\Models\ServiceComponent's own doc comment.
 *
 * component-payment is the one row App\Integrations\Payment\
 * SandboxPaymentConnector actually enforces against at runtime (seeded
 * DISABLED/REQUIRES_AUTHORITY_CONTRACT) -- every other row remains
 * display-only, matching the source exactly.
 */
class ServiceComponentSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $components = [
            ['id' => 'component-web', 'component_key' => 'WEB_APP', 'display_name' => 'VAT-MSA web application', 'component_type' => 'APPLICATION', 'criticality' => 'HIGH', 'configuration_status' => 'CONFIGURED', 'operational_status' => 'OPERATIONAL', 'dependency_summary' => 'Laravel application runtime', 'status_detail' => 'Release gate and readiness checks passed.'],
            ['id' => 'component-d1', 'component_key' => 'D1', 'display_name' => 'Structured transactional state', 'component_type' => 'DATABASE', 'criticality' => 'CRITICAL', 'configuration_status' => 'CONFIGURED', 'operational_status' => 'OPERATIONAL', 'dependency_summary' => 'MySQL database connection', 'status_detail' => 'Schema initialisation and prepared-query probe passed.'],
            ['id' => 'component-r2', 'component_key' => 'R2_DOCUMENTS', 'display_name' => 'Private document quarantine', 'component_type' => 'OBJECT_STORAGE', 'criticality' => 'HIGH', 'configuration_status' => 'CONFIGURED', 'operational_status' => 'QUARANTINE_ONLY', 'dependency_summary' => 'Local filesystem disk (see docs/DEPLOYMENT.md Storage section)', 'status_detail' => 'Uploads remain quarantined pending an external malware scanner.'],
            ['id' => 'component-itas', 'component_key' => 'ITAS', 'display_name' => 'ITAS statutory integration', 'component_type' => 'EXTERNAL', 'criticality' => 'CRITICAL', 'configuration_status' => 'REQUIRES_AUTHORITY_CONTRACT', 'operational_status' => 'DISABLED', 'dependency_summary' => 'NamRA/ITAS contract, credentials and approved mappings', 'status_detail' => 'No legal filing or taxpayer verification is claimed.'],
            ['id' => 'component-hsm', 'component_key' => 'SIGNING_HSM', 'display_name' => 'Production certificate signing', 'component_type' => 'SECURITY', 'criticality' => 'CRITICAL', 'configuration_status' => 'REQUIRES_SECURITY_CONTRACT', 'operational_status' => 'DISABLED', 'dependency_summary' => 'HSM/KMS keys and approved signature profile', 'status_detail' => 'Development signatures are not production legal signatures.'],
            ['id' => 'component-events', 'component_key' => 'OUTBOX', 'display_name' => 'Durable event outbox', 'component_type' => 'MESSAGING', 'criticality' => 'HIGH', 'configuration_status' => 'CONFIGURED', 'operational_status' => 'PENDING_CONSUMER', 'dependency_summary' => 'outbox_events table and external publisher', 'status_detail' => 'Events are durable; external broker publisher is not configured.'],
            ['id' => 'component-payment', 'component_key' => 'PAYMENT_CONNECTOR', 'display_name' => 'Refund payment connector', 'component_type' => 'EXTERNAL', 'criticality' => 'CRITICAL', 'configuration_status' => 'REQUIRES_AUTHORITY_CONTRACT', 'operational_status' => 'DISABLED', 'dependency_summary' => 'Bank/payment gateway contract, settlement account and NamRA payment authority approval', 'status_detail' => 'Payment is DISABLED PENDING AUTHORITY -- no live payment instruction is issued; RecordPayment/AllocatePayment refuse to run until this row is authorised.'],
        ];

        foreach ($components as $component) {
            DB::table('service_components')->updateOrInsert(
                ['id' => $component['id']],
                [
                    'component_key' => $component['component_key'], 'display_name' => $component['display_name'],
                    'component_type' => $component['component_type'], 'criticality' => $component['criticality'],
                    'configuration_status' => $component['configuration_status'], 'operational_status' => $component['operational_status'],
                    'dependency_summary' => $component['dependency_summary'], 'last_checked_at' => $now,
                    'status_detail' => $component['status_detail'],
                ],
            );
        }
    }
}
