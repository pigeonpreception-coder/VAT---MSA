<?php

namespace App\Models;

use App\Support\Access\Permissions;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Merges the TS source's `app_users` onto Laravel's native Authenticatable
 * user (see the 0001_01_01_000000_b_create_users_table migration's own
 * comment for why). `role` is the single source of RBAC truth, ported
 * 1:1 from lib/domain/access.ts -- see App\Support\Access\Permissions.
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'external_user_id', 'name', 'email', 'password', 'role', 'taxpayer_id', 'status',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function taxpayer(): BelongsTo
    {
        return $this->belongsTo(Taxpayer::class);
    }

    public function identityLinks(): HasMany
    {
        return $this->hasMany(IdentityLink::class);
    }

    public function organisationMemberships(): HasMany
    {
        return $this->hasMany(OrganisationMembership::class);
    }

    /**
     * Module 1's isNationalScope (lib/domain/access.ts): a national-scope
     * actor has no taxpayer_id and holds one of the national-only roles --
     * NamRA/pilot-admin/internal-audit/security roles that see across every
     * tenant rather than being confined to one.
     */
    public function isNationalScope(): bool
    {
        return $this->taxpayer_id === null && in_array($this->role, Permissions::NATIONAL_SCOPE_ROLES, true);
    }

    /** Module 1's hasPermission -- static role permissions only; dynamic (tenant-granted) permissions are resolved separately once organisation-role grants are migrated (Phase 7/8). */
    public function hasAppPermission(string $permission): bool
    {
        return Permissions::roleHas($this->role, $permission);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
