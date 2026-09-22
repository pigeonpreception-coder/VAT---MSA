<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Services\Developer\DeveloperPlatformService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ported from app/api/v1/developer/clients/route.ts and
 * .../clients/[id]/rotation/route.ts, .../revocation/route.ts,
 * .../conformance-runs/route.ts (lib/data/developer-repository.ts's
 * createClient/rotateCredential/revokeCredential/runConformance).
 */
class DeveloperPlatformController extends Controller
{
    public function __construct(
        private readonly DeveloperPlatformService $developer,
        private readonly OrganisationResolver $organisations,
    ) {}

    public function create(Request $request): JsonResponse
    {
        $this->authorize('permission', 'developer:manage');
        $user = $request->user();

        $client = $this->developer->createClient($user, (array) $request->input(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['client' => $client], 201);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'developer:manage');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));

        $client = $this->developer->revokeCredential($organisation, $id, (array) $request->input(), $user, $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['client' => $client]);
    }

    public function rotate(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'developer:manage');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));

        $client = $this->developer->rotateCredential($organisation, $id, $user, $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['client' => $client]);
    }

    public function runConformance(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'developer:manage');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));

        $run = $this->developer->runConformance($organisation, $id, $user, $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['test_run' => $run], 201);
    }

    private function idempotencyKey(Request $request): string
    {
        return (string) $request->header('Idempotency-Key', '');
    }

    private function correlationId(): string
    {
        return (string) Str::uuid();
    }
}
