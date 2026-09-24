<?php

namespace App\Models;

use App\Support\Access\DynamicPermissions;
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
     * Multi-tenant SaaS pivot phase 4 (2026-09-24): the single convenience
     * accessor every controller-side currency/branding literal this phase
     * swept now goes through, in place of re-deriving `taxpayer->
     * organisation` at each call site. Null for a national-scope actor
     * (no `taxpayer_id` at all -- see isNationalScope()'s own doc
     * comment) or a taxpayer whose Organisation row somehow doesn't exist;
     * every caller treats that the same way Organisation's own resolver
     * methods treat an unresolvable tax authority -- default to today's
     * only tenant's values, never an error.
     *
     * Queries `organisations` directly (by `taxpayer_id`) rather than
     * going through the `taxpayer()` relation first -- one query instead
     * of two -- and memoizes via setRelation()/getRelation() so repeated
     * calls on the same instance (the global tenant-branding view composer
     * calls this once per rendered view, including every `@include`d
     * partial, and this session's own SupplierLedgerViewTest asserts a
     * fixed, row-count-independent query ceiling every page must respect)
     * cost exactly one query for the whole request, not one per call --
     * safe because `Auth::user()` returns the same cached model instance
     * throughout a request.
     */
    public function organisation(): ?Organisation
    {
        if (! $this->relationLoaded('organisation')) {
            $this->setRelation('organisation', $this->taxpayer_id ? Organisation::where('taxpayer_id', $this->taxpayer_id)->first() : null);
        }

        return $this->getRelation('organisation');
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

    /**
     * Module 1's hasPermission: static role permissions OR the tenant-
     * granted dynamic permissions an organisation-defined custom role
     * assigns (`App\Support\Access\DynamicPermissions`, closed in Phase 12
     * slice 2's portal-navigation work once `organisation_roles`/
     * `user_role_assignments` existed to resolve it from).
     */
    public function hasAppPermission(string $permission): bool
    {
        return Permissions::roleHas($this->role, $permission)
            || in_array($permission, DynamicPermissions::forUser($this), true);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
