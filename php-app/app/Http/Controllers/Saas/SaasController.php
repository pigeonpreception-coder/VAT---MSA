<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Services\Saas\SaasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ported from app/api/v1/saas-providers/route.ts,
 * app/api/v1/saas-providers/[id]/usage/route.ts and
 * app/api/v1/saas-applications/[id]/conformance-runs/route.ts
 * (lib/api/saas.ts's handleSaasCommand/handleSaasUsage) -- Module 10 Phase
 * C: SaaS provider onboarding. Gated on `developer:manage`/`developer:read`,
 * the same permissions the Developer portal already checks.
 */
class SaasController extends Controller
{
    public function __construct(private readonly SaasService $saas) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'developer:read');

        return response()->json(['resources' => $this->saas->index($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'developer:manage');

        $resource = $this->saas->registerProvider((array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $resource], 201);
    }

    public function usage(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'developer:read');

        return response()->json($this->saas->getUsage($id, $request->user()));
    }

    public function submitConformance(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'developer:manage');

        $resource = $this->saas->submitConformance($id, (array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $resource], 201);
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
