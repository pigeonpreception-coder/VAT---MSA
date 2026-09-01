<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Business\ProjectService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Ported from app/api/v1/projects/{route,[id]/{budget-approval,costs,profitability}}/route.ts (Module 5 Phase E). */
class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects, private readonly OrganisationResolver $organisations) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'projects:read');
        $organisation = $this->organisations->resolve($request->user(), $request->query('organisation_id'));
        $projects = Project::where('organisation_id', $organisation->id)->orderByDesc('start_date')->limit(100)->get();

        return response()->json(['organisation_id' => $organisation->id, 'projects' => $projects]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'projects:manage');
        $correlationId = (string) Str::uuid();
        $project = $this->projects->create((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $project], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function approveBudget(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'projects:manage');
        $correlationId = (string) Str::uuid();
        $budget = $this->projects->approveBudget($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $budget], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function postCost(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'projects:manage');
        $correlationId = (string) Str::uuid();
        $cost = $this->projects->postCost($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $cost], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function profitability(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'projects:read');

        return response()->json($this->projects->profitability($id, $request->user(), $request->query('organisation_id')));
    }
}
