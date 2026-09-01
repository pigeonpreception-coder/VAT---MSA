<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Taxpayer;
use App\Models\User;
use Illuminate\Database\Seeder;
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
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $taxpayer = Taxpayer::updateOrCreate(
            ['vat_number' => 'VAT-DEMO-0001'],
            [
                'id' => (string) Str::uuid(),
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
                'id' => (string) Str::uuid(),
                'legal_name' => $taxpayer->legal_name,
                'trading_name' => $taxpayer->trading_name,
                'status' => 'ACTIVE',
            ],
        );

        $branch = Branch::updateOrCreate(
            ['organisation_id' => $organisation->id, 'code' => 'HEAD'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Head Office',
                'address' => $taxpayer->address,
                'status' => 'ACTIVE',
                'is_head_office' => true,
            ],
        );

        $owner = User::updateOrCreate(
            ['email' => 'owner@demo-trading.test'],
            [
                'id' => (string) Str::uuid(),
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
                'id' => (string) Str::uuid(),
                'role_code' => 'TAXPAYER_OWNER',
                'branch_id' => $branch->id,
                'status' => 'ACTIVE',
                'valid_from' => now(),
            ],
        );

        $admin = User::updateOrCreate(
            ['email' => 'admin@vat-msa.test'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'NamRA Pilot Admin',
                'password' => Hash::make('password'),
                'role' => 'PILOT_ADMIN',
                'taxpayer_id' => null,
                'status' => 'ACTIVE',
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info("Demo login: owner@demo-trading.test / password (TAXPAYER_OWNER)");
        $this->command?->info("Admin login: admin@vat-msa.test / password (PILOT_ADMIN, national scope)");
    }
}
