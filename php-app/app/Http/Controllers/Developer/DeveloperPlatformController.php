<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Services\Developer\DeveloperPlatformService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ported from app/api/v1/developer/clients/[id]/rotation/route.ts and
 * .../conformance-runs/route.ts (lib/data/developer-repository.ts's
 * rotateCredential/runConformance) -- see App\Services\Developer\
 * DeveloperPlatformService's own doc comment for what this deliberately
 * does not re-port (CreateClient/RevokeCredential).
 */
class DeveloperPlatformController extends Controller
{
    public function __construct(
        private readonly DeveloperPlatformService $developer,
        private readonly OrganisationResolver $organisations,
    ) {}

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
