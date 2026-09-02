<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganisationMembership extends Model
{
    use BelongsToOrganisation, HasUuids;

    public $timestamps = false;

    protected $fillable = ['organisation_id', 'user_id', 'role_code', 'branch_id', 'status', 'valid_from', 'valid_to', 'assigned_by'];

    protected $casts = ['valid_from' => 'datetime', 'valid_to' => 'datetime'];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
