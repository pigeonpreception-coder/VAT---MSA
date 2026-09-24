<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ported from db/runtime.ts's `integration_connections` table -- Module 10
 * Phase A's generic, provider-agnostic connector model. First real write
 * path: App\Services\Integration\IntegrationConnectionService.
 *
 * `organisation_id` NULL means a platform-wide connection (registered by a
 * national/platform-technical actor with no taxpayer of their own); a
 * present value scopes the row to that organisation. Two pre-seeded
 * platform-wide government connections (ITAS, E-Tariff/ETARIFF -- see
 * database/seeders/IntegrationConnectionSeeder, added multi-tenant SaaS
 * pivot phase 5, 2026-09-24) exist as rows here with free-text
 * "REQUIRES_*_CONTRACT" `configuration_status` values that deliberately
 * fall outside this service's own closed DRAFT/CONFIGURED/SUSPENDED enum
 * -- see App\Domain\Integration\IntegrationValidator::assertTransition's
 * own doc comment for why that keeps ApproveIntegration from ever
 * touching either row. BIPA and the banking/treasury connections a much
 * earlier doc comment here once also claimed were pre-seeded are not --
 * that was never true of any of the four; only ITAS/E-Tariff are, and
 * only as of phase 5, genuinely scoped to "generalize the ITAS/E-Tariff
 * port contracts" (docs/MIGRATION_MATRIX.md's own phase 5 entry).
 */
class IntegrationConnection extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'last_health_check_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function syncJobs(): HasMany
    {
        return $this->hasMany(SyncJob::class, 'integration_connection_id');
    }

    /** @return list<string> */
    public function capabilityList(): array
    {
        $decoded = json_decode((string) $this->capabilities, true);

        return is_array($decoded) ? $decoded : [];
    }
}
