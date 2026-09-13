<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Developer Portal's `api_clients` table (Module 10) had no Eloquent
 * model and no command writing to it (schema-only; read via raw
 * DB::table() by PlatformSnapshotService::developerPortalSnapshot() for
 * display only). App\Services\Integration\PosApiClientService is the
 * first real write path: a real, working credential a taxpayer's own
 * private Point-of-Sale system authenticates with to push invoices in
 * real time (App\Http\Middleware\AuthenticatePosApiClient), per the
 * user's own explicit request that this integration be genuinely
 * functional rather than an "awaiting confirmation" stub -- unlike
 * ITAS/E-Tariff, both ends of this integration are this application's own
 * to build.
 *
 * `credential_reference` holds a bcrypt hash of the client secret (never
 * the plaintext, which is shown to the issuing user exactly once at
 * creation and never persisted or displayed again) -- there is no
 * external secrets-manager in this environment for that column to
 * reference instead, so this is the pragmatic, honest choice for where a
 * verifiable-but-not-recoverable credential actually lives.
 */
class ApiClient extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'last_rotated_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function developerAccount(): BelongsTo
    {
        return $this->belongsTo(DeveloperAccount::class);
    }

    /** @return list<string> */
    public function scopeList(): array
    {
        $decoded = json_decode((string) $this->scopes, true);

        return is_array($decoded) ? $decoded : [];
    }
}
