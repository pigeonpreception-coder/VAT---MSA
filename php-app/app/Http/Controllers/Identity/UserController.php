<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Services\Identity\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ported from app/api/v1/users/[id]/suspension/route.ts and
 * .../reactivation/route.ts (lib/data/identity-repository.ts's
 * suspendUser/reactivateUser).
 */
class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function suspend(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'administration:manage');
        // Step-up: see routes/web.php's 'step-up' middleware on this route.
        $suspension = $this->users->suspend($request->user(), $id, (array) $request->json()->all(), (string) Str::uuid());

        return response()->json(['suspension' => $suspension]);
    }

    public function reactivate(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'administration:manage');
        // Step-up: see routes/web.php's 'step-up' middleware on this route.
        $reactivation = $this->users->reactivate($request->user(), $id, (string) Str::uuid());

        return response()->json(['reactivation' => $reactivation]);
    }
}
