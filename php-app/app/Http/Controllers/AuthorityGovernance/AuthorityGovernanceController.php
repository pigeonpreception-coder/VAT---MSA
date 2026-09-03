<?php

namespace App\Http\Controllers\AuthorityGovernance;

use App\Http\Controllers\Controller;
use App\Services\AuthorityGovernance\AuthorityGovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/tax-authority-onboarding-cases/{route,[id]/decisions/route}.ts.
 * GET's URL is the source's own (a snapshot read living at a
 * cases-shaped path, not a mismatch introduced here) --
 * `App\Http\Controllers\Portal\NamraAdminPortalController` reuses
 * `AuthorityGovernanceService::getSnapshot` directly rather than calling
 * this JSON route internally, the same "no second query path" precedent
 * every other portal controller in this migration already established.
 *
 * `stepUpEvidenceReference` is computed server-side as
 * `"verified-step-up:{$correlationId}"`, matching the source's own
 * route (`` `verified-step-up:${context.correlationId}` ``) exactly --
 * never client-supplied. Step-up itself is the `password.confirm`
 * middleware on these routes (routes/web.php), this migration's own
 * established equivalent of the source's `requireStepUp`.
 */
class AuthorityGovernanceController extends Controller
{
    public function __construct(private readonly AuthorityGovernanceService $governance) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('permission', 'authority-governance:read');

        return response()->json(['governance' => $this->governance->getSnapshot($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'authority-governance:manage');
        $correlationId = (string) Str::uuid();
        $case = $this->governance->createOnboardingCase(
            $request->user(), (array) $request->json()->all(), (string) $request->header('Idempotency-Key', ''), $correlationId,
        );

        return response()->json(['onboarding_case' => $case, 'production_activation_effect' => false], Response::HTTP_CREATED);
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'authority-governance:manage');
        $correlationId = (string) Str::uuid();
        $case = $this->governance->decideOnboardingCase(
            $request->user(), $id, (array) $request->json()->all(), (string) $request->header('Idempotency-Key', ''),
            $correlationId, "verified-step-up:{$correlationId}",
        );

        return response()->json(['onboarding_case' => $case, 'production_activation_effect' => false]);
    }
}
