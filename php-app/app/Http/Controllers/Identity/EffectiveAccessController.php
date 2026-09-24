<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Models\UserCapabilityAssignment;
use App\Support\Access\DynamicPermissions;
use App\Support\Access\Permissions;
use App\Support\Access\TaxpayerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gap-finding pass (2026-09-24): ported from app/api/v1/me/access/route.ts,
 * which calls lib/domain/access.ts's getUserAccess -- a self-service
 * "effective access" readback (organisation/taxpayer/role/national-scope/
 * capabilities/permissions) for the current session. No separate
 * permission gate in source beyond authentication: this only ever reflects
 * the caller's own access, never another user's, so there is nothing else
 * to authorize.
 */
class EffectiveAccessController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $organisationId = DynamicPermissions::homeOrganisationId($user);

        // Matches lib/auth.ts's buildUserContext exactly: status='ACTIVE'
        // only, no effective_from/effective_to date-window filter (unlike
        // OrganisationCapability's own convention elsewhere in this
        // migration) -- this table's source query never applies one.
        $capabilities = $organisationId
            ? UserCapabilityAssignment::where('user_id', $user->id)->where('organisation_id', $organisationId)
                ->where('status', 'ACTIVE')->pluck('capability')->unique()->sort()->values()->all()
            : [];

        $permissions = collect(Permissions::effectiveForRole($user->role))
            ->merge(DynamicPermissions::forUser($user))
            ->unique()->sort()->values()->all();

        return response()->json([
            'user_id' => $user->id,
            'organisation_id' => $organisationId,
            'taxpayer_id' => $user->taxpayer_id,
            'role' => $user->role,
            'is_national_scope' => TaxpayerScope::isNational($user),
            // Source's own local-step-up dev bypass (isDevelopmentIdentity
            // -- see App\Support\Access\StepUp's own doc comment) was
            // deliberately never ported: this deployment always requires a
            // real TOTP step-up, so no identity of this type exists here.
            'is_development_identity' => false,
            'capabilities' => $capabilities,
            'permissions' => $permissions,
        ]);
    }
}
