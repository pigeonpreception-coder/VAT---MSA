<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 7's organisation-scope trait pilot (App\Models\Concerns\
 * BelongsToOrganisation): App\Services\Business\BusinessPartyService
 * already scopes every one of its own queries to
 * OrganisationResolver::resolve()'s organisation by hand, so this trait
 * adds a provably redundant defense-in-depth backstop here, not a
 * behaviour change -- see the trait's own doc comment for why this was
 * chosen as the first model to adopt it.
 */
class BusinessParty extends Model
{
    use BelongsToOrganisation, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(PartyRelationship::class, 'party_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
