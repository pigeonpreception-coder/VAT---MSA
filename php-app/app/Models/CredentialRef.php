<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `credential_refs` table (Module 10) -- the rotation/revocation
 * history behind an `api_clients` row's own `credential_reference`
 * column. First real write path: App\Services\Integration\
 * PosApiClientService writes one row here on issuance and updates it (or
 * writes a fresh row, for a rotation) on revocation.
 */
class CredentialRef extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }
}
