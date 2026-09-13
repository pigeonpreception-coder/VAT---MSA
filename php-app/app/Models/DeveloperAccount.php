<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Developer Portal's `developer_accounts` table (Module 10) had no
 * Eloquent model and no command writing to it -- get-or-created by
 * CreateClient "rather than a separate command", per that migration's own
 * doc comment. App\Services\Integration\PosApiClientService is the first
 * real command reaching this table: a taxpayer's own private
 * Point-of-Sale integration needs a real, working API credential, and an
 * api_clients row requires a developer_accounts row to belong to.
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
