<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\AssignMembershipRequest;
use App\Services\Identity\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/** Ported from app/api/v1/organisations/[id]/memberships/route.ts. */
class MembershipController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function store(AssignMembershipRequest $request, string $organisation): JsonResponse
    {
        $this->authorize('permission', 'organisations:manage');
        // Step-up: see routes/web.php's 'password.confirm' middleware on this route.

        $membership = $this->memberships->assign($request->user(), $organisation, $request->validated(), (string) Str::uuid());

        return response()->json(['membership' => $membership], 201);
    }
}
