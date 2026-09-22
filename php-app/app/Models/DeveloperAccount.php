<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Developer Portal's `developer_accounts` table (Module 10) --
 * get-or-created "rather than a separate command", per that migration's own
 * doc comment. Two independent commands reach this table: this port's own
 * App\Services\Integration\PosApiClientService (a taxpayer's private
 * Point-of-Sale integration credential) and, faithfully to source,
 * App\Services\Developer\DeveloperPlatformService::createClient (the
 * Developer Portal's own arbitrary-scope client). Both get-or-create the
 * same one-per-organisation+owner-user row; an api_clients row always needs
 * a developer_accounts row to belong to.
 */
class DeveloperAccount extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function apiClients(): HasMany
    {
        return $this->hasMany(ApiClient::class);
    }
}
