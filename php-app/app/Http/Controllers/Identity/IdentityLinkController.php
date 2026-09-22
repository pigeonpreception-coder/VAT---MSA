<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Services\Identity\IdentityLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ported from app/api/v1/identity/links/route.ts and
 * .../links/[id]/revocation/route.ts (lib/data/identity-repository.ts's
 * listIdentityLinks/linkIdentity/revokeIdentityLink) -- see
 * App\Services\Identity\IdentityLinkService's own doc comment for why
 * this is administrative bookkeeping/audit in this port rather than a
 * command with a real session-invalidation effect.
 */
class IdentityLinkController extends Controller
{
    public function __construct(private readonly IdentityLinkService $links) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'identity:read');
        $user = $request->user();
        $requestedUserId = $request->query('user_id');
        $userId = ($requestedUserId && $requestedUserId !== $user->id) ? $requestedUserId : $user->id;
        if ($userId !== $user->id) {
            $this->authorize('permission', 'administration:manage');
        }

        return response()->json(['user_id' => $userId, 'links' => $this->links->list($userId)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'administration:manage');
        // Step-up: see routes/web.php's 'step-up' middleware on this route.

        $link = $this->links->link($request->user(), (array) $request->json()->all(), (string) Str::uuid());

        return response()->json(['link' => $link], 201);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'administration:manage');
        // Step-up: see routes/web.php's 'step-up' middleware on this route.

        $revocation = $this->links->revoke($request->user(), $id, (string) Str::uuid());

        return response()->json(['revocation' => $revocation]);
    }
}
