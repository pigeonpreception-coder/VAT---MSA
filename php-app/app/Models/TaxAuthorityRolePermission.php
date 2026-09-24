<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenant SaaS pivot phase 6 (2026-09-24): one row is one permission
 * a tax authority's own role catalogue grants a built-in role code (see
 * the 2026_09_24_000004 migration's own doc comment for the full design).
 * Read almost exclusively through `App\Support\Access\AuthorityRolePermissions`,
 * not this model directly -- exposed mainly for seeders/admin tooling and
 * the tests proving the override behaviour.
 */
class TaxAuthorityRolePermission extends Model
{
    use HasUuids;

    protected $fillable = ['tax_authority_id', 'role_code', 'permission_code'];

    public function taxAuthority(): BelongsTo
    {
        return $this->belongsTo(TaxAuthority::class);
    }
}
