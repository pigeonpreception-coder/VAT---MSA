<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organisation extends Model
{
    use HasUuids;

    protected $fillable = ['taxpayer_id', 'tax_authority_id', 'legal_name', 'trading_name', 'status'];

    public function taxpayer(): BelongsTo
    {
        return $this->belongsTo(Taxpayer::class);
    }

    /**
     * Multi-tenant SaaS pivot phase 2 (2026-09-24): which licensed
     * national platform this organisation belongs to -- see
     * database/migrations/2026_09_24_000001_add_tax_authority_id_to_organisations_table.php's
     * own doc comment. Defaults to NamRA at the database level, so every
     * organisation created before this column existed, and every test
     * fixture that never names one, still resolves to today's only
     * tenant without any code change required here.
     */
    public function taxAuthority(): BelongsTo
    {
        return $this->belongsTo(TaxAuthority::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganisationMembership::class);
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(OrganisationCapability::class);
    }
}
