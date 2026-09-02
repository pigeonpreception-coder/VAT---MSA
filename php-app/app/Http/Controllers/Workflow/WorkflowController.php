<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Services\Workflow\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/workflows/**, app/api/v1/workflow-tasks/[id]/
 * decision/route.ts -- Phase 12's workflow-engine slice (Module 8 Phase
 * C), the last of `control-plane-repository.ts`'s sub-domains besides
 * the `getAdministrationSnapshot` dashboard aggregate. GET /workflows
 * bundles into that aggregate (deferred), so only its POST half is
 * ported here; GET /workflows/delegations is a genuinely standalone
 * read and is ported directly.
 */
class WorkflowController extends Controller
{
    public function __construct(private readonly WorkflowService $workflows) {}

    public function storeWorkflow(Request $request): JsonResponse
    {
        $this->authorize('permission', 'workflows:manage');
        $workflow = $this->workflows->createWorkflowDraft((array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['workflow' => $workflow], Response::HTTP_CREATED);
    }

    public function publishVersion(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'workflows:manage');
        $version = $this->workflows->publishWorkflowVersion($id, $request->user(), $request->query('organisation_id'));

        return response()->json(['workflowVersion' => $version]);
    }

    public function testVersion(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'workflows:read');
        $result = $this->workflows->testWorkflowVersion($id, (array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['test' => $result]);
    }

    public function storeInstance(Request $request): JsonResponse
    {
        $this->authorize('permission', 'workflows:manage');
        $instance = $this->workflows->assignWorkflow((array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['instance' => $instance], Response::HTTP_CREATED);
    }

    public function decideTask(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'workflows:decide');
        $decision = $this->workflows->decideWorkflowTask($id, (array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['decision' => $decision]);
    }

    public function delegations(Request $request): JsonResponse
    {
        $this->authorize('permission', 'workflows:read');

        return response()->json(['delegations' => $this->workflows->listDelegations($request->user(), $request->query('organisation_id'))]);
    }

    public function storeDelegation(Request $request): JsonResponse
    {
        $this->authorize('permission', 'workflows:manage');
        $delegation = $this->workflows->createDelegation((array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['delegation' => $delegation], Response::HTTP_CREATED);
    }

    public function revokeDelegation(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'workflows:manage');
        $delegation = $this->workflows->revokeDelegation($id, (array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['delegation' => $delegation]);
    }
}
