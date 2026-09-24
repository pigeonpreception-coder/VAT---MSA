<?php

namespace App\Models;

use App\Support\Access\AuthorityRolePermissions;
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
     * of two -- and memoizes via a plain instance property (not
     * setRelation()/getRelation(): a genuine bug found in multi-tenant
     * SaaS pivot phase 6 -- `organisation` was never a real Eloquent
     * relationship method, so once anything set it via setRelation(),
     * Model::refresh()'s own `$this->load(array_keys($this->relations))`
     * tried to eager-load it as one and crashed with "Call to a member
     * function addEagerConstraints() on null". Harmless as long as nothing
     * called both organisation() and refresh() on the same instance in one
     * request/test -- true until phase 6's own hasAppPermission() change
     * made organisation() resolution part of every authorization check,
     * not just view rendering, which TenantRoleEscalationTest's own
     * `$admin->refresh()` after a permission check then exposed) so
     * repeated calls on the same instance (the global tenant-branding view
     * composer calls this once per rendered view, including every
     * `@include`d partial, and this session's own SupplierLedgerViewTest
     * asserts a fixed, row-count-independent query ceiling every page must
     * respect) still cost exactly one query for the whole request, not one
     * per call -- safe because `Auth::user()` returns the same cached
     * model instance throughout a request.
     */
    private bool $organisationResolved = false;

    private ?Organisation $organisationCache = null;

    public function organisation(): ?Organisation
    {
        if (! $this->organisationResolved) {
            $this->organisationCache = $this->taxpayer_id ? Organisation::where('taxpayer_id', $this->taxpayer_id)->first() : null;
            $this->organisationResolved = true;
        }

        return $this->organisationCache;
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
     * Module 1's hasPermission: the role's permission set -- an authority-
     * specific catalogue if one exists (`App\Support\Access\
     * AuthorityRolePermissions`, multi-tenant SaaS pivot phase 6),
     * otherwise `Permissions::ROLE_PERMISSIONS` exactly as before -- OR the
     * tenant-granted dynamic permissions an organisation-defined custom
     * role assigns (`App\Support\Access\DynamicPermissions`, closed in
     * Phase 12 slice 2's portal-navigation work once `organisation_roles`/
     * `user_role_assignments` existed to resolve it from).
     *
     * `AuthorityRolePermissions::forRole()`'s own result is memoized per
     * instance for the same reason organisation()'s own doc comment gives:
     * every `->authorize('permission', ...)` call in every controller
     * funnels through here, often several times per rendered page (once
     * per row's "can edit" flag, in several list views this pivot's own
     * BudgetsViewTest/ProjectManagementViewTest/SupplierLedgerViewTest
     * assert a fixed query ceiling for) -- without this, phase 6's own
     * addition turned one query per page into one query per permission
     * check, a real regression those three tests caught before this
     * shipped.
     */
    private ?array $resolvedRolePermissionsCache = null;

    public function hasAppPermission(string $permission): bool
    {
        if ($this->resolvedRolePermissionsCache === null) {
            $this->resolvedRolePermissionsCache = AuthorityRolePermissions::forRole($this->taxAuthorityId(), $this->role);
        }

        return in_array($permission, $this->resolvedRolePermissionsCache, true)
            || in_array($permission, DynamicPermissions::forUser($this), true);
    }

    /**
     * Multi-tenant SaaS pivot phase 6 (2026-09-24): which tax authority's
     * own role catalogue should resolve this user's static role grant --
     * null for a national-scope actor (no `organisation()` to resolve one
     * from, same as `organisation()`'s own null case) or a taxpayer-scoped
     * user whose Organisation row somehow doesn't exist. `hasAppPermission()`
     * is the only caller that matters for correctness; `EffectiveAccessController`
     * reuses it so the self-service "effective access" screen reflects the
     * same catalogue.
     */
    public function taxAuthorityId(): ?string
    {
        return $this->organisation()?->tax_authority_id;
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
