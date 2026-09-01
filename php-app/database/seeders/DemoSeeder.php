<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\OrganisationMembership;
use App\Models\Taxpayer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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

        $this->command?->info("Demo login: owner@demo-trading.test / password (TAXPAYER_OWNER)");
        $this->command?->info("Demo customer VAT number for invoice testing: VAT-DEMO-0002");
        $this->command?->info("Admin login: admin@vat-msa.test / password (PILOT_ADMIN, national scope)");
    }
}
