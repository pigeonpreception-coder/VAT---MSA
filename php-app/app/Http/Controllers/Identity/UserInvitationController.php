<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\InviteUserRequest;
use App\Services\Identity\UserInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Ported from app/api/v1/organisations/[id]/invitations/route.ts's POST
 * handler -- Module 1 Identity ProvisionUser, invite half. See
 * App\Services\Identity\UserInvitationService::claim()'s own doc comment
 * for the claim half, exposed as a Blade-only flow
 * (App\Http\Controllers\Identity\InvitationClaimController) rather than a
 * JSON route, since it no longer has a faithful machine-callable shape once
 * reworked to set a real password.
 */
class UserInvitationController extends Controller
{
    public function __construct(private readonly UserInvitationService $invitations) {}

    public function store(InviteUserRequest $request, string $organisation): JsonResponse
    {
        $this->authorize('permission', 'organisations:manage');
        // Step-up: see routes/web.php's 'step-up' middleware on this route.

        $invitation = $this->invitations->invite($request->user(), $organisation, $request->validated(), (string) Str::uuid());

        return response()->json(['invitation' => $invitation], 201);
    }
}
