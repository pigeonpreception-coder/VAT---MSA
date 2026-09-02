<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRoleAssignment extends Model
{
    use BelongsToOrganisation, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['effective_from' => 'datetime', 'effective_to' => 'datetime', 'created_at' => 'datetime'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(OrganisationRole::class, 'organisation_role_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
