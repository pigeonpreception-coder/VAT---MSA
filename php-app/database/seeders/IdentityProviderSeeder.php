<?php

namespace Database\Seeders;

use App\Models\IdentityProvider;
use Illuminate\Database\Seeder;

/**
 * Ported verbatim from db/runtime.ts's identity_providers seed rows --
 * deploy-time reference data, not application-writable: no command
 * anywhere in the source (or this port) ever creates an identity
 * provider row. Without it, `IdentityFoundationSnapshotService`'s own
 * `providers` field would always be empty, even though this data exists
 * unconditionally in the source's own seed. Kept as its own seeder
 * (rather than folded into DemoSeeder) since it is genuine platform
 * configuration, not demo-user/organisation fixture data.
 */
class IdentityProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['provider_key' => 'SITES_WORKSPACE', 'display_name' => 'Workspace authenticated identity', 'provider_type' => 'PLATFORM', 'authority_level' => 'AUTHENTICATION', 'issuer' => null, 'status' => 'ACTIVE', 'configuration_status' => 'CONFIGURED'],
            ['provider_key' => 'ITAS', 'display_name' => 'ITAS identity provider', 'provider_type' => 'GOVERNMENT', 'authority_level' => 'PREFERRED_AUTHORITATIVE', 'issuer' => null, 'status' => 'PENDING', 'configuration_status' => 'REQUIRES_ITAS_CONFIRMATION'],
            ['provider_key' => 'VAT_MSA_STANDALONE', 'display_name' => 'VAT-MSA standalone identity', 'provider_type' => 'MANAGED_EXTERNAL', 'authority_level' => 'CONTINUITY', 'issuer' => null, 'status' => 'PENDING', 'configuration_status' => 'REQUIRES_SECURITY_DECISION'],
        ];
        foreach ($providers as $provider) {
            IdentityProvider::updateOrCreate(['provider_key' => $provider['provider_key']], $provider);
        }
    }
}
