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
 * present value scopes the row to that organisation. The four pre-seeded
 * government/banking/treasury connections (ITAS, BIPA, bank-org1,
 * treasury) already exist as rows here with free-text
 * "REQUIRES_*_CONTRACT" `configuration_status` values that deliberately
 * fall outside this service's own closed DRAFT/CONFIGURED/SUSPENDED enum
 * -- see App\Domain\Integration\IntegrationValidator::assertTransition's
 * own doc comment for why that keeps ApproveIntegration from ever
 * touching those four rows.
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
