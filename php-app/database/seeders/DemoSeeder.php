<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\OrganisationMembership;
use App\Models\SodRule;
use App\Models\Taxpayer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A minimal local/staging fixture mirroring db/runtime.ts's own dev-only
 * seed pattern (a demo taxpayer/organisation with a real owner login) --
 * enough to verify the Phase 6 auth flow and Phase 7 authorization gate
 * end-to-end. Not the same identities as the TS source's own seed rows
 * (those carry Cloudflare-Sites-authenticated external_user_ids that mean
 * nothing under local Laravel auth); a real password is set here instead,
 * since that is the whole point of this phase.
 *
 * Every updateOrCreate below deliberately omits 'id' from the update-values
 * array (Phase 9 fix): every model here uses HasUuids, which auto-assigns
 * 'id' on create only -- putting it in the values array instead re-assigned
 * a *fresh* random id to the row on every re-seed of an already-existing
 * row, which then broke every FK pointing at the old id (caught live via a
 * 1451 constraint violation reseeding this phase's own capability grants).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $taxpayer = Taxpayer::updateOrCreate(
            ['vat_number' => 'VAT-DEMO-0001'],
            [
                'tin' => 'TIN-DEMO-0001',
                'legal_name' => 'Demo Trading Co (Pty) Ltd',
                'trading_name' => 'Demo Trading',
                'taxpayer_type' => 'PRIVATE_COMPANY',
                'vat_status' => 'ACTIVE',
                'return_frequency' => 'MONTHLY',
                'address' => '1 Independence Avenue, Windhoek',
                'email' => 'finance@demo-trading.test',
            ],
        );

        $organisation = Organisation::updateOrCreate(
            ['taxpayer_id' => $taxpayer->id],
            [
                'legal_name' => $taxpayer->legal_name,
                'trading_name' => $taxpayer->trading_name,
                'status' => 'ACTIVE',
            ],
        );

        $branch = Branch::updateOrCreate(
            ['organisation_id' => $organisation->id, 'code' => 'HEAD'],
            [
                'name' => 'Head Office',
                'address' => $taxpayer->address,
                'status' => 'ACTIVE',
                'is_head_office' => true,
            ],
        );

        $owner = User::updateOrCreate(
            ['email' => 'owner@demo-trading.test'],
            [
                'name' => 'Demo Owner',
                'password' => Hash::make('password'),
                'role' => 'TAXPAYER_OWNER',
                'taxpayer_id' => $taxpayer->id,
                'status' => 'ACTIVE',
                'email_verified_at' => now(),
            ],
        );

        OrganisationMembership::updateOrCreate(
            ['organisation_id' => $organisation->id, 'user_id' => $owner->id],
            [
                'role_code' => 'TAXPAYER_OWNER',
                'branch_id' => $branch->id,
                'status' => 'ACTIVE',
                'valid_from' => now(),
            ],
        );

        // Phase 9: InvoiceService::submit resolves supplier/customer via the dynamic
        // BUYER/SELLER organisation_capabilities grant, never a static role -- grant
        // the demo organisation both (matching RegistrationService::decide's own
        // default on approval) so a demo login can certify invoices end-to-end.
        foreach (['BUYER', 'SELLER'] as $capability) {
            OrganisationCapability::updateOrCreate(
                ['organisation_id' => $organisation->id, 'capability' => $capability],
                ['status' => 'ACTIVE', 'effective_from' => now(), 'approved_by' => null, 'created_at' => now()],
            );
        }

        // App\Support\Licensing\LicenseResolver::getLicense throws "The
        // organisation has no configured licence" for any organisation with
        // no organisation_licenses row -- this demo organisation never had
        // one, silently blocking every EntitlementGate-gated screen (the new
        // Administration/Licensing view included) for the demo login. No
        // command in this port ever creates a license from scratch (only
        // changeState/upgrade, both of which themselves require one to
        // already exist) -- confirmed this is a genuine, pre-existing demo
        // seed gap, the same class of finding as "Demo seed gaps for
        // already-shipped features" in docs/MIGRATION_MATRIX.md, not
        // something a real onboarding flow would ever hit.
        $subscription = DB::table('subscriptions')->where('provider', 'DEMO')->where('provider_reference', $organisation->id)->first();
        if (! $subscription) {
            $subscriptionId = (string) Str::uuid();
            DB::table('subscriptions')->insert([
                'id' => $subscriptionId, 'organisation_id' => $organisation->id, 'provider' => 'DEMO',
                'provider_reference' => $organisation->id, 'status' => 'ACTIVE', 'activated_at' => now(),
                'current_period_start' => now()->startOfMonth()->toDateString(), 'current_period_end' => now()->addYear()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            $subscriptionId = $subscription->id;
        }
        if (! DB::table('organisation_licenses')->where('organisation_id', $organisation->id)->exists()) {
            DB::table('organisation_licenses')->insert([
                'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'subscription_id' => $subscriptionId,
                'license_plan_id' => 'plan-pilot-professional-v1', 'state' => 'ACTIVE', 'state_version' => 1,
                'effective_from' => now(), 'effective_to' => null, 'grace_ends_at' => null,
                'retention_policy' => 'STANDARD', 'updated_at' => now(),
            ]);
        }

        // A second demo taxpayer purely as a registered-buyer counterparty, so a
        // demo invoice can be certified against a real customer (status MATCHED)
        // rather than only the unregistered-buyer path (status CERTIFIED, risk+15).
        $customerTaxpayer = Taxpayer::updateOrCreate(
            ['vat_number' => 'VAT-DEMO-0002'],
            [
                'tin' => 'TIN-DEMO-0002',
                'legal_name' => 'Demo Customer Enterprises CC',
                'trading_name' => 'Demo Customer',
                'taxpayer_type' => 'CLOSE_CORPORATION',
                'vat_status' => 'ACTIVE',
                'return_frequency' => 'MONTHLY',
                'address' => '10 Sam Nujoma Drive, Windhoek',
                'email' => 'accounts@demo-customer.test',
            ],
        );
        $customerOrganisation = Organisation::updateOrCreate(
            ['taxpayer_id' => $customerTaxpayer->id],
            [
                'legal_name' => $customerTaxpayer->legal_name,
                'trading_name' => $customerTaxpayer->trading_name,
                'status' => 'ACTIVE',
            ],
        );
        foreach (['BUYER', 'SELLER'] as $capability) {
            OrganisationCapability::updateOrCreate(
                ['organisation_id' => $customerOrganisation->id, 'capability' => $capability],
                ['status' => 'ACTIVE', 'effective_from' => now(), 'approved_by' => null, 'created_at' => now()],
            );
        }

        $admin = User::updateOrCreate(
            ['email' => 'admin@vat-msa.test'],
            [
                'name' => 'NamRA Pilot Admin',
                'password' => Hash::make('password'),
                'role' => 'PILOT_ADMIN',
                'taxpayer_id' => null,
                'status' => 'ACTIVE',
                'email_verified_at' => now(),
            ],
        );

        // Phase 5 gap: sod_rules/consent_grants/delegations are all
        // confirmed (via a full-repo grep, same as document_metadata's own
        // "no command creates a row here" precedent) to be seed-only,
        // read-only governance data -- but each is now read by an
        // already-shipped feature (WorkflowService::decideTask's own SoD-
        // violation logging; ComplianceSnapshotService::getSnapshot's
        // consents/delegations arrays) that silently never exercises that
        // path without a seeded row. Matches the source's own demo seed
        // data (sod-no-self-approval/sod-no-create-approve-execute/
        // consent-0001/delegation-0001 for org-0001), with dates kept
        // relative to "now" rather than the source's fixed 2026-08 dates so
        // they still read as current whenever this seeder actually runs.
        SodRule::updateOrCreate(
            ['organisation_id' => $organisation->id, 'code' => 'NO_SELF_APPROVAL'],
            [
                'name' => 'No self approval', 'action_set' => json_encode(['CREATE', 'APPROVE']),
                'scope' => 'ALL_PROTECTED_WORKFLOWS', 'mandatory' => true, 'status' => 'ACTIVE',
                'effective_from' => now()->subMonth(), 'created_at' => now()->subMonth(),
            ],
        );
        SodRule::updateOrCreate(
            ['organisation_id' => $organisation->id, 'code' => 'NO_CREATE_APPROVE_EXECUTE'],
            [
                'name' => 'Separate create approve and execute', 'action_set' => json_encode(['CREATE', 'APPROVE', 'EXECUTE']),
                'scope' => 'PAYMENT_AND_TAX_SENSITIVE', 'mandatory' => true, 'status' => 'ACTIVE',
                'effective_from' => now()->subMonth(), 'created_at' => now()->subMonth(),
            ],
        );

        DB::table('consent_grants')->updateOrInsert(
            ['organisation_id' => $organisation->id, 'grantee_id' => 'TAXPAYER_ACCOUNTANT'],
            [
                'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'granted_by' => $owner->id,
                'grantee_type' => 'ROLE', 'purpose' => 'VAT return preparation',
                'data_categories' => json_encode(['INVOICES', 'VAT_LEDGER', 'RETURNS']), 'legal_basis' => 'TAXPAYER_INSTRUCTION',
                'status' => 'ACTIVE', 'valid_from' => now()->subMonth(), 'valid_to' => now()->addMonths(6),
                'revoked_at' => null, 'created_at' => now()->subMonth(),
            ],
        );
        DB::table('delegations')->updateOrInsert(
            ['organisation_id' => $organisation->id, 'delegator_user_id' => $owner->id, 'delegate_user_id' => $admin->id],
            [
                'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'scopes' => json_encode(['returns:read', 'audit:read']),
                'status' => 'ACTIVE', 'valid_from' => now()->subMonth(), 'valid_to' => now()->addMonths(3),
                'approved_by' => $owner->id, 'approved_at' => now()->subMonth(), 'created_at' => now()->subMonth(),
            ],
        );

        // Authority Governance demo data (NamRA Administration portal) --
        // ported from db/runtime.ts's own 'usr-local-admin'/
        // 'usr-authority-onboarding-maker'/'usr-authority-governance-approver'
        // seed rows against 'tax-authority-na-namra'
        // (AuthorityGovernanceSeeder's own reference data), substituting
        // two real logins for the source's placeholder Cloudflare-Sites
        // identities -- $admin plays 'usr-local-admin' (the system
        // administrator); a new NAMRA_SYSTEM_ADMIN login plays
        // 'usr-authority-onboarding-maker' (the case requester), giving a
        // second real administrator so the maker-checker self-review
        // denial and the decide command are genuinely demonstrable, not
        // just readable.
        $namraAdmin = User::updateOrCreate(
            ['email' => 'namra-admin@vat-msa.test'],
            [
                'name' => 'NamRA System Administrator', 'password' => Hash::make('password'), 'role' => 'NAMRA_SYSTEM_ADMIN',
                'taxpayer_id' => null, 'status' => 'ACTIVE', 'email_verified_at' => now(),
            ],
        );
        foreach ([$admin->id, $namraAdmin->id] as $userId) {
            DB::table('tax_authority_administrators')->updateOrInsert(
                ['tax_authority_id' => 'tax-authority-na-namra', 'user_id' => $userId],
                [
                    'id' => (string) Str::uuid(), 'status' => 'ACTIVE', 'effective_from' => now()->subMonth(), 'effective_to' => null,
                    'appointed_by' => 'SYNTHETIC_ARCHITECTURE_BASELINE', 'approval_reference' => 'LOCAL-STAGING-ADR-030',
                ],
            );
        }
        $hqUnit = (string) Str::uuid();
        $identityUnit = (string) Str::uuid();
        DB::table('tax_authority_units')->updateOrInsert(
            ['tax_authority_id' => 'tax-authority-na-namra', 'code' => 'HQ'],
            ['id' => $hqUnit, 'parent_unit_id' => null, 'name' => 'Head Office', 'unit_type' => 'HEAD_OFFICE', 'status' => 'ACTIVE', 'created_at' => now()],
        );
        $hqUnit = DB::table('tax_authority_units')->where('tax_authority_id', 'tax-authority-na-namra')->where('code', 'HQ')->value('id');
        DB::table('tax_authority_units')->updateOrInsert(
            ['tax_authority_id' => 'tax-authority-na-namra', 'code' => 'DOMESTIC_TAX'],
            ['id' => (string) Str::uuid(), 'parent_unit_id' => $hqUnit, 'name' => 'Domestic Taxes Directorate', 'unit_type' => 'DIRECTORATE', 'status' => 'ACTIVE', 'created_at' => now()],
        );
        DB::table('tax_authority_units')->updateOrInsert(
            ['tax_authority_id' => 'tax-authority-na-namra', 'code' => 'IDENTITY_SECURITY'],
            ['id' => $identityUnit, 'parent_unit_id' => $hqUnit, 'name' => 'Identity and Access Governance', 'unit_type' => 'DIVISION', 'status' => 'ACTIVE', 'created_at' => now()],
        );
        $identityUnit = DB::table('tax_authority_units')->where('tax_authority_id', 'tax-authority-na-namra')->where('code', 'IDENTITY_SECURITY')->value('id');

        DB::table('tax_authority_role_assignments')->updateOrInsert(
            ['tax_authority_id' => 'tax-authority-na-namra', 'user_id' => $namraAdmin->id, 'role_code' => 'AUTHORITY_ONBOARDING_MAKER', 'authority_unit_id' => $hqUnit],
            [
                'id' => (string) Str::uuid(), 'scope' => json_encode(['jurisdiction' => 'NA', 'environment' => 'LOCAL_STAGING']),
                'status' => 'ACTIVE', 'effective_from' => now()->subMonth(), 'effective_to' => null,
                'requested_by' => $namraAdmin->id, 'approved_by' => $admin->id, 'approval_reference' => 'ISSUE4-SYNTHETIC-MAKER-ASSIGNMENT', 'created_at' => now(),
            ],
        );
        DB::table('tax_authority_role_assignments')->updateOrInsert(
            ['tax_authority_id' => 'tax-authority-na-namra', 'user_id' => $admin->id, 'role_code' => 'AUTHORITY_SYSTEM_ADMIN', 'authority_unit_id' => $identityUnit],
            [
                'id' => (string) Str::uuid(), 'scope' => json_encode(['jurisdiction' => 'NA', 'environment' => 'LOCAL_STAGING']),
                'status' => 'ACTIVE', 'effective_from' => now()->subMonth(), 'effective_to' => null,
                'requested_by' => $namraAdmin->id, 'approved_by' => $admin->id, 'approval_reference' => 'ISSUE4-SYNTHETIC-SYSTEM-ASSIGNMENT', 'created_at' => now(),
            ],
        );

        $itasProviderId = DB::table('identity_providers')->where('provider_key', 'ITAS')->value('id');
        if ($itasProviderId) {
            DB::table('tax_authority_federation_connections')->updateOrInsert(
                ['tax_authority_id' => 'tax-authority-na-namra', 'identity_provider_id' => $itasProviderId, 'environment' => 'CONTRACT_PENDING'],
                [
                    'id' => (string) Str::uuid(), 'protocol' => 'UNCONFIRMED', 'issuer' => null, 'audience' => null,
                    'metadata_hash' => null, 'claims_contract_hash' => null, 'assurance_profile' => null, 'status' => 'CONTRACT_PENDING',
                    'requested_by' => $namraAdmin->id, 'reviewed_by' => null, 'checked_at' => null, 'expires_at' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ],
            );
        }

        $onboardingCaseId = (string) Str::uuid();
        DB::table('tax_authority_onboarding_cases')->updateOrInsert(
            ['tax_authority_id' => 'tax-authority-na-namra', 'target_environment' => 'LOCAL_STAGING', 'status' => 'SUBMITTED'],
            [
                'id' => $onboardingCaseId,
                'purpose' => 'Validate the authority hierarchy, governance and independent approval workflow using synthetic local data only.',
                'evidence_bundle_hash' => null, 'readiness_reference' => 'ISSUE4-LOCAL-STAGING', 'requested_by' => $namraAdmin->id,
                'submitted_at' => now(), 'approved_at' => null, 'activated_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ],
        );
        $onboardingCaseId = DB::table('tax_authority_onboarding_cases')
            ->where('tax_authority_id', 'tax-authority-na-namra')->where('target_environment', 'LOCAL_STAGING')->where('status', 'SUBMITTED')
            ->value('id');
        DB::table('tax_authority_governance_events')->updateOrInsert(
            ['onboarding_case_id' => $onboardingCaseId, 'event_type' => 'TaxAuthorityOnboardingRequested'],
            [
                'id' => (string) Str::uuid(), 'tax_authority_id' => 'tax-authority-na-namra', 'from_status' => null, 'to_status' => 'SUBMITTED',
                'reason_code' => 'LOCAL_STAGING_REVIEW_REQUIRED', 'evidence_hash' => null, 'actor_id' => $namraAdmin->id, 'occurred_at' => now(),
            ],
        );

        // A current QUARTERLY access review -- decideOnboardingCase's own
        // requireCurrentAuthorityReview gate needs one to exist, matching
        // the source's own dynamically-computed-quarter seed row (kept
        // relative to "now" for the same reason this file's consent_grants/
        // delegations rows already are, per that block's own comment above).
        DB::table('tax_authority_access_reviews')->updateOrInsert(
            ['tax_authority_id' => 'tax-authority-na-namra', 'review_type' => 'QUARTERLY', 'period_start' => now()->startOfQuarter()->toDateString()],
            [
                'id' => (string) Str::uuid(), 'due_at' => now()->addMonths(3)->addDays(14), 'status' => 'OPEN',
                'owner_id' => $admin->id, 'completed_by' => null, 'completed_at' => null, 'created_at' => now(),
            ],
        );

        $this->command?->info("Demo login: owner@demo-trading.test / password (TAXPAYER_OWNER)");
        $this->command?->info("Demo customer VAT number for invoice testing: VAT-DEMO-0002");
        $this->command?->info("Admin login: admin@vat-msa.test / password (PILOT_ADMIN, national scope)");
        $this->command?->info("NamRA admin login: namra-admin@vat-msa.test / password (NAMRA_SYSTEM_ADMIN)");
    }
}
